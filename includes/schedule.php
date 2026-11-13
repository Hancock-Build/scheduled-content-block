<?php
/** Shared schedule evaluation for rendering and cache boundaries. */
if ( ! defined( 'ABSPATH' ) ) exit;

/** Match the editor's earlier occurrence in a repeated hour; normalize DST gaps forward. */
function scblk_recurrence_local_ts( $local ) {
        $timezone = wp_timezone();
        $parsed = new DateTimeImmutable( $local, $timezone );
        $result = $parsed->getTimestamp();
        $wall = ( new DateTimeImmutable( $local, new DateTimeZone( 'UTC' ) ) )->getTimestamp();
        foreach ( $timezone->getTransitions( $result - DAY_IN_SECONDS, $result + DAY_IN_SECONDS ) ?: array() as $transition ) {
                $candidate = $wall - $transition['offset'];
                if ( $candidate < $result && wp_date( 'Y-m-d\\TH:i:s', $candidate, $timezone ) === $local ) $result = $candidate;
        }
        return $result;
}

/** Shift from the original boundary, preserving site wall time without month-end drift. */
function scblk_recurrence_boundary( $timestamp, $frequency, $interval, $index, $window_start = null ) {
        if ( 0 === $index ) return $timestamp;
        $time = ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() );
        $anchor = null === $window_start ? $time : ( new DateTimeImmutable( '@' . $window_start ) )->setTimezone( wp_timezone() );
        // Keep overnight/multi-day windows intact when their start is clamped in a short month.
        $first_day = new DateTimeImmutable( $anchor->format( 'Y-m-d' ), new DateTimeZone( 'UTC' ) );
        $last_day = new DateTimeImmutable( $time->format( 'Y-m-d' ), new DateTimeZone( 'UTC' ) );
        $day_span = (int) $first_day->diff( $last_day )->format( '%r%a' );
        $amount = $interval * $index;
        if ( 'daily' === $frequency || 'weekly' === $frequency ) {
                $days = $amount * ( 'weekly' === $frequency ? 7 : 1 );
                $shifted = $anchor->setTime( 12, 0 )->modify( '+' . $days . ' days' );
        } else {
                $months = (int) $anchor->format( 'Y' ) * 12 + (int) $anchor->format( 'n' ) - 1;
                $months += $amount * ( 'yearly' === $frequency ? 12 : 1 );
                $year = intdiv( $months, 12 );
                $month = $months % 12 + 1;
                $first = $anchor->setTime( 12, 0 )->setDate( $year, $month, 1 );
                $shifted = $first->setDate( $year, $month, min( (int) $anchor->format( 'j' ), (int) $first->format( 't' ) ) );
        }
        // PHP moves nonexistent spring-forward times forward by the DST gap.
        if ( $day_span ) $shifted = $shifted->modify( sprintf( '%+d days', $day_span ) );
        return scblk_recurrence_local_ts( $shifted->format( 'Y-m-d' ) . 'T' . $time->format( 'H:i:s' ) );
}

/** Find the first occurrence strictly after now without walking the whole series. */
function scblk_next_recurrence_index( $timestamp, $frequency, $interval, $now, $window_start = null ) {
        if ( $timestamp > $now ) return 0;
        $low = 0;
        $high = 1;
        while ( scblk_recurrence_boundary( $timestamp, $frequency, $interval, $high, $window_start ) <= $now ) {
                $low = $high;
                $high *= 2;
        }
        while ( $high - $low > 1 ) {
                $middle = intdiv( $low + $high, 2 );
                if ( scblk_recurrence_boundary( $timestamp, $frequency, $interval, $middle, $window_start ) <= $now ) $low = $middle;
                else $high = $middle;
        }
        return $high;
}

/**
 * Evaluate one-off or recurring windows. Endpoints remain inclusive.
 * For recurrence, start and end describe the first window and repeat indefinitely.
 */
function scblk_schedule_state( $attributes, $now ) {
        $atts = wp_parse_args( $attributes, array( 'start' => '', 'end' => '', 'recurrence' => 'none', 'recurrenceInterval' => 1 ) );
        $start = scblk_parse_site_ts( $atts['start'] );
        $end = scblk_parse_site_ts( $atts['end'] );
        $frequency = $atts['recurrence'];
        $repeating = 'none' !== $frequency;
        $interval = $atts['recurrenceInterval'];
        $invalid = ( '' !== $atts['start'] && null === $start ) || ( '' !== $atts['end'] && null === $end ) ||
                ( null !== $start && null !== $end && $end < $start );
        if ( $repeating ) {
                $invalid = $invalid || ! in_array( $frequency, array( 'daily', 'weekly', 'monthly', 'yearly' ), true ) ||
                        ! ( is_int( $interval ) || is_float( $interval ) ) || floor( $interval ) != $interval ||
                        $interval < 1 || $interval > 365 || null === $start || null === $end || $end <= $start;
        }
        if ( $invalid ) return array( 'status' => 'invalid', 'boundaries' => array() );

        $boundaries = array();
        if ( $repeating ) {
                $interval = (int) $interval;
                $next = scblk_next_recurrence_index( $start, $frequency, $interval, $now );
                $boundaries[] = array( scblk_recurrence_boundary( $start, $frequency, $interval, $next ), 'start' );
                $next_end = scblk_next_recurrence_index( $end, $frequency, $interval, $now - 1, $start );
                // Purge after the inclusive end, so cached content cannot remain visible in the gap.
                $boundaries[] = array( scblk_recurrence_boundary( $end, $frequency, $interval, $next_end, $start ) + 1, 'end' );
                if ( 0 === $next ) $status = 'upcoming';
                else {
                        $window_end = scblk_recurrence_boundary( $end, $frequency, $interval, $next - 1, $start );
                        $status = $now <= $window_end ? 'active' : 'inactive';
                }
        } else {
                if ( null !== $start && $start > $now ) $boundaries[] = array( $start, 'start' );
                if ( null !== $end && $end > $now ) $boundaries[] = array( $end, 'end' );
                if ( null === $start && null === $end ) $status = 'unscheduled';
                elseif ( null !== $start && $now < $start ) $status = 'upcoming';
                elseif ( null !== $end && $now > $end ) $status = 'expired';
                else $status = 'active';
        }
        return array( 'status' => $status, 'boundaries' => $boundaries );
}

/** Recurring blocks must survive the end of every occurrence, including stale deletion jobs. */
function scblk_can_delete_after_end( $atts ) {
        return ! empty( $atts['deleteAfterEnd'] ) && ! empty( $atts['end'] ) &&
                ( ! isset( $atts['recurrence'] ) || 'none' === $atts['recurrence'] );
}
