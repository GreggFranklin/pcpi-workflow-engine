<?php
function pcpi_get_workflow_from_entry( $entry ) {

    if ( empty( $entry ) ) return '';

    // 1. Applicant form
    $workflow = rgar( $entry, '1005' );
    if ( $workflow ) return $workflow;

    // 2. Extract from URL
    if ( ! empty( $entry['source_url'] ) ) {

        $parts = parse_url( $entry['source_url'] );

        if ( ! empty( $parts['query'] ) ) {

            parse_str( $parts['query'], $query );

            if ( ! empty( $query['workflow'] ) ) {
                return sanitize_text_field( $query['workflow'] );
            }
        }
    }

    return '';
}


function pcpi_get_agency_id_from_entry( $entry ) {

    $workflow = pcpi_get_workflow_from_entry( $entry );

    if ( ! $workflow ) return 0;

    if ( class_exists( 'PCPI_Workflow_Engine' ) ) {

        $config = PCPI_Workflow_Engine::get_workflow( $workflow );

        return isset( $config['agency_id'] )
            ? (int) $config['agency_id']
            : 0;
    }

    return 0;
}


function pcpi_get_agency_data( $agency_id ) {

    if ( ! $agency_id ) return [];

    $logo_id = get_post_meta( $agency_id, '_pcpi_logo_id', true );

    return [
        'name'    => get_the_title( $agency_id ),
        'address' => get_post_meta( $agency_id, '_pcpi_address', true ),
        'phone'   => get_post_meta( $agency_id, '_pcpi_phone', true ),
        'logo'    => $logo_id
            ? wp_get_attachment_image_url( $logo_id, 'medium' )
            : '',
    ];
}