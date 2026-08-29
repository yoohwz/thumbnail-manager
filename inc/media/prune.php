<?php
/**
 * Pure prune candidate, evidence, filesystem, and metadata primitives.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deduplicate and sort immutable ownership evidence before persistence.
 *
 * @param array $candidate Candidate payload.
 * @return array
 */
function yotm_prune_normalize_candidate_evidence( $candidate ) {
	foreach ( array( 'metadata_refs', 'ownership_evidence' ) as $field ) {
		$normalized = array();
		foreach ( (array) ( $candidate[ $field ] ?? array() ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$key                = absint( $entry['attachment_id'] ?? 0 ) . ':' . sanitize_key( $entry['size'] ?? '' ) . ':' . sanitize_file_name( $entry['filename'] ?? '' ) . ':' . sanitize_key( $entry['selection'] ?? '' );
			$normalized[ $key ] = $entry;
		}
		ksort( $normalized );
		$candidate[ $field ] = array_values( $normalized );
	}

	return $candidate;
}

/**
 * Return the immutable historical cohort safety constants.
 *
 * @return array
 */
function yotm_historical_cohort_constants() {
	return array(
		'min_metadata_anchors'  => 2,
		'min_disk_observations' => 3,
		'min_families'          => 5,
		'min_ratio_buckets'     => 3,
		'min_directories'       => 2,
		'max_anchor_size_keys'  => 100,
		'max_witness_pool'      => 10,
	);
}

/**
 * Return one exact reduced integer ratio key.
 *
 * @param int $width Width.
 * @param int $height Height.
 * @return string
 */
function yotm_historical_ratio_key( $width, $height ) {
	$width  = absint( $width );
	$height = absint( $height );
	if ( ! $width || ! $height ) {
		return '';
	}
	$a = $width;
	$b = $height;
	while ( 0 !== $b ) {
		$remainder = $a % $b;
		$a         = $b;
		$b         = $remainder;
	}
	$gcd = max( 1, $a );
	return intdiv( $width, $gcd ) . ':' . intdiv( $height, $gcd );
}

/**
 * Build the canonical historical output signature.
 *
 * @param int    $width Width.
 * @param int    $height Height.
 * @param string $mime Decoded MIME.
 * @return string
 */
function yotm_historical_signature( $width, $height, $mime ) {
	$mime = sanitize_mime_type( $mime );
	if ( ! absint( $width ) || ! absint( $height ) || '' === $mime ) {
		return '';
	}
	return absint( $width ) . 'x' . absint( $height ) . ':' . $mime;
}

/**
 * Hash the immutable policy state that may authorize verified legacy items.
 *
 * @param array $payload Prune job payload.
 * @return string
 */
function yotm_legacy_policy_hash( $payload ) {
	$sizes = array();
	foreach ( (array) ( $payload['sizes'] ?? array() ) as $name => $definition ) {
		if ( ! is_array( $definition ) ) {
			continue;
		}
		$sizes[ (string) $name ] = array(
			'width'  => absint( $definition['width'] ?? 0 ),
			'height' => absint( $definition['height'] ?? 0 ),
			'crop'   => ! empty( $definition['crop'] ),
		);
	}
	ksort( $sizes );
	$keep       = array_values( array_map( 'strval', (array) ( $payload['keep'] ?? array() ) ) );
	$remove     = array_values( array_map( 'strval', (array) ( $payload['remove'] ?? array() ) ) );
	$subpaths   = array_values( array_map( 'strval', (array) ( $payload['selection_subpaths'] ?? array() ) ) );
	$scan_bases = array_values( array_map( 'yotm_normalize_filesystem_path', (array) ( $payload['scan_bases'] ?? array() ) ) );
	sort( $keep );
	sort( $remove );
	sort( $subpaths );
	sort( $scan_bases );

	$version = absint( $payload['legacy_policy']['version'] ?? 1 );
	$data    = array(
		'version'            => 1,
		'keep'               => $keep,
		'remove'             => $remove,
		'sizes'              => $sizes,
		'selector'           => (string) ( $payload['selector'] ?? '' ),
		'selection_meta_max' => absint( $payload['selection_meta_max'] ?? 0 ),
		'selection_subpaths' => $subpaths,
		'scan_bases'         => $scan_bases,
	);
	if ( $version >= 2 ) {
		$data['version']                         = 2;
		$data['current_disabled_enabled']        = ! empty( $payload['legacy_policy']['current_disabled_enabled'] ) ? 1 : 0;
		$data['historical_unregistered_enabled'] = ! empty( $payload['legacy_policy']['historical_unregistered_enabled'] ) ? 1 : 0;
		$data['historical_constants']            = is_array( $payload['legacy_policy']['constants'] ?? null ) ? $payload['legacy_policy']['constants'] : array();
		$data['ratio_schema']                    = (string) ( $payload['legacy_policy']['ratio_schema'] ?? '' );
	}

	return hash(
		'sha256',
		(string) wp_json_encode( $data )
	);
}

/**
 * Verify that one persisted job explicitly enabled legacy evidence version 1.
 *
 * @param array $payload Prune job payload.
 * @return true|WP_Error
 */
function yotm_legacy_policy_validate( $payload ) {
	$policy  = is_array( $payload['legacy_policy'] ?? null ) ? $payload['legacy_policy'] : array();
	$version = absint( $policy['version'] ?? 0 );
	$enabled = 1 === $version
		? ! empty( $policy['enabled'] )
		: ( ! empty( $policy['current_disabled_enabled'] ) || ! empty( $policy['historical_unregistered_enabled'] ) );
	if ( empty( $payload['discover_orphans'] ) || ! in_array( $version, array( 1, 2 ), true ) || ! $enabled ) {
		return new WP_Error( 'yotm_legacy_policy_unavailable', __( 'This prune job does not contain reviewed legacy-cleanup policy evidence.', 'thumbnail-manager' ) );
	}
	$expected = yotm_legacy_policy_hash( $payload );
	$actual   = (string) ( $policy['hash'] ?? '' );
	if ( ! preg_match( '/^[a-f0-9]{64}$/', $actual ) || ! hash_equals( $expected, $actual ) ) {
		return new WP_Error( 'yotm_legacy_policy_changed', __( 'The persisted legacy-cleanup policy is incomplete or changed.', 'thumbnail-manager' ) );
	}

	return true;
}

/**
 * Verify that one job explicitly enabled the historical tier.
 *
 * @param array $payload Prune job payload.
 * @return true|WP_Error
 */
function yotm_historical_policy_validate( $payload ) {
	$valid  = yotm_legacy_policy_validate( $payload );
	$policy = is_array( $payload['legacy_policy'] ?? null ) ? $payload['legacy_policy'] : array();
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}
	if ( 2 !== absint( $policy['version'] ?? 0 ) || empty( $policy['historical_unregistered_enabled'] ) ) {
		return new WP_Error( 'yotm_historical_policy_unavailable', __( 'This prune job does not contain reviewed historical-cleanup policy evidence.', 'thumbnail-manager' ) );
	}
	if ( yotm_historical_cohort_constants() !== ( $policy['constants'] ?? null ) || 'integer_gcd_v1' !== ( $policy['ratio_schema'] ?? '' ) ) {
		return new WP_Error( 'yotm_historical_policy_changed', __( 'The persisted historical-cleanup constants are incomplete or changed.', 'thumbnail-manager' ) );
	}
	return true;
}

/**
 * Return the allowed decoded MIME for one legacy filename extension.
 *
 * @param string $extension Filename extension.
 * @return string
 */
function yotm_legacy_extension_mime( $extension ) {
	$map = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
		'avif' => 'image/avif',
	);

	return $map[ strtolower( (string) $extension ) ] ?? '';
}

/**
 * Parse and decode one strict Core-style disk-only derivative.
 *
 * @param string   $path Candidate path.
 * @param string[] $scan_bases Selected canonical scan roots.
 * @return array|WP_Error
 */
function yotm_legacy_parse_disk_candidate( $path, $scan_bases ) {
	$lexical   = yotm_prune_journal_lexical_path( $path );
	$canonical = yotm_media_source_canonical_path( $lexical );
	if ( is_wp_error( $canonical ) || ! hash_equals( $lexical, (string) $canonical ) || ! yotm_is_path_inside_any_dir( $canonical, $scan_bases ) ) {
		return new WP_Error( 'yotm_legacy_path_invalid', __( 'The disk-only path is outside the exact reviewed uploads scope.', 'thumbnail-manager' ) );
	}
	$node = yotm_prune_journal_path_state( $canonical );
	if ( is_wp_error( $node ) || 'regular' !== ( $node['state'] ?? '' ) ) {
		return is_wp_error( $node ) ? $node : new WP_Error( 'yotm_legacy_node_invalid', __( 'The disk-only path is not a regular non-symlink file.', 'thumbnail-manager' ) );
	}

	$filename = wp_basename( $canonical );
	if ( ! preg_match( '/^(.+)-([1-9][0-9]*)x([1-9][0-9]*)\.(jpe?g|png|gif|webp|avif)$/i', $filename, $matches ) ) {
		return new WP_Error( 'yotm_legacy_filename_invalid', __( 'The disk-only filename is not one strict generated-size form.', 'thumbnail-manager' ) );
	}
	$stem = (string) $matches[1];
	if (
		preg_match( '/(?:-[0-9]+x[0-9]+|-e[0-9]+|-scaled|-rotated)$/i', $stem )
		|| preg_match( '/(?:^|[._-])(?:bak|backup|orig|original|old|tmp|temp)(?:$|[._-])/i', $stem )
	) {
		return new WP_Error( 'yotm_legacy_sibling_ambiguous', __( 'The disk-only filename is an ambiguous edit, scale, rotation, or backup sibling.', 'thumbnail-manager' ) );
	}

	$image         = wp_getimagesize( $canonical );
	$mime          = is_array( $image ) ? sanitize_mime_type( $image['mime'] ?? '' ) : '';
	$width         = absint( $matches[2] );
	$height        = absint( $matches[3] );
	$expected_mime = yotm_legacy_extension_mime( $matches[4] );
	if ( ! is_array( $image ) || absint( $image[0] ?? 0 ) !== $width || absint( $image[1] ?? 0 ) !== $height || '' === $expected_mime || ! hash_equals( $expected_mime, $mime ) ) {
		return new WP_Error( 'yotm_legacy_image_mismatch', __( 'The disk-only file dimensions or MIME type do not match its filename.', 'thumbnail-manager' ) );
	}
	$file_hash = hash_file( 'sha256', $canonical );
	if ( ! is_string( $file_hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $file_hash ) ) {
		return new WP_Error( 'yotm_legacy_file_unreadable', __( 'The disk-only file could not be hashed exactly.', 'thumbnail-manager' ) );
	}

	return array(
		'path'        => $canonical,
		'filename'    => $filename,
		'directory'   => dirname( $canonical ),
		'stem'        => $stem,
		'width'       => $width,
		'height'      => $height,
		'mime'        => $mime,
		'bytes'       => absint( $node['bytes'] ?? 0 ),
		'fingerprint' => (string) ( $node['fingerprint'] ?? '' ),
		'file_hash'   => $file_hash,
	);
}

/**
 * Return registered size names that project to exact observed dimensions.
 *
 * @param string[] $names Registered size names.
 * @param array    $sizes Registered definitions.
 * @param int      $source_width Source width.
 * @param int      $source_height Source height.
 * @param int      $width Observed output width.
 * @param int      $height Observed output height.
 * @return string[]
 */
function yotm_legacy_matching_size_names( $names, $sizes, $source_width, $source_height, $width, $height ) {
	$matched = array();
	foreach ( (array) $names as $name ) {
		if ( ! isset( $sizes[ $name ] ) || ! is_array( $sizes[ $name ] ) ) {
			continue;
		}
		$definition = $sizes[ $name ];
		$projected  = image_resize_dimensions(
			absint( $source_width ),
			absint( $source_height ),
			absint( $definition['width'] ?? 0 ),
			absint( $definition['height'] ?? 0 ),
			! empty( $definition['crop'] )
		);
		if ( is_array( $projected ) && absint( $projected[4] ?? 0 ) === absint( $width ) && absint( $projected[5] ?? 0 ) === absint( $height ) ) {
			$matched[] = (string) $name;
		}
	}
	sort( $matched );

	return array_values( array_unique( $matched ) );
}

/**
 * Return current live registered-size projections matching one output.
 *
 * @param int $source_width Source width.
 * @param int $source_height Source height.
 * @param int $width Output width.
 * @param int $height Output height.
 * @return string[]
 */
function yotm_historical_live_projection_names( $source_width, $source_height, $width, $height ) {
	$live = yotm_get_registered_sizes();
	return yotm_legacy_matching_size_names( array_keys( $live ), $live, $source_width, $source_height, $width, $height );
}

/**
 * Build exact fixed-output historical witness fields.
 *
 * @param string $kind Anchor or observation.
 * @param int    $attachment_id Attachment ID.
 * @param string $source Authoritative generation source.
 * @param string $source_hash Source content hash.
 * @param int    $source_width Source width.
 * @param int    $source_height Source height.
 * @param array  $info Parsed candidate information.
 * @param array  $job_payload Job policy/scope.
 * @return array|WP_Error
 */
function yotm_historical_witness_fields( $kind, $attachment_id, $source, $source_hash, $source_width, $source_height, $info, $job_payload ) {
	$source_width  = absint( $source_width );
	$source_height = absint( $source_height );
	$output_width  = absint( $info['width'] ?? 0 );
	$output_height = absint( $info['height'] ?? 0 );
	$source_ratio  = yotm_historical_ratio_key( $source_width, $source_height );
	$output_ratio  = yotm_historical_ratio_key( $output_width, $output_height );
	if (
		$source_width <= $output_width
		|| $source_height <= $output_height
		|| '' === $source_ratio
		|| '' === $output_ratio
		|| hash_equals( $source_ratio, $output_ratio )
	) {
		return new WP_Error( 'yotm_historical_shape_unsupported', __( 'The historical-looking file is not a provable fixed-output hard crop.', 'thumbnail-manager' ) );
	}
	$base      = trailingslashit( yotm_normalize_filesystem_path( (string) ( $job_payload['base'] ?? '' ) ) );
	$base_dir  = untrailingslashit( $base );
	$directory = yotm_normalize_filesystem_path( (string) ( $info['directory'] ?? '' ) );
	if ( '' === $base_dir || ( $directory !== $base_dir && ! yotm_is_path_inside_dir( $directory, $base_dir ) ) ) {
		return new WP_Error( 'yotm_historical_directory_invalid', __( 'The historical evidence directory is outside uploads.', 'thumbnail-manager' ) );
	}
	$relative_directory = ltrim( substr( trailingslashit( $directory ), strlen( $base ) ), '/\\' );
	$signature          = yotm_historical_signature( $output_width, $output_height, $info['mime'] ?? '' );
	$family_key         = hash(
		'sha256',
		absint( $attachment_id ) . ':' . hash( 'sha256', (string) $source ) . ':' . (string) $source_hash
	);
	$witness_key        = hash( 'sha256', sanitize_key( $kind ) . ':' . $family_key . ':' . hash( 'sha256', (string) ( $info['path'] ?? '' ) ) );

	return array(
		'historical_signature'   => $signature,
		'historical_family_key'  => $family_key,
		'historical_witness_key' => $witness_key,
		'source_ratio_key'       => $source_ratio,
		'output_ratio_key'       => $output_ratio,
		'directory_key'          => hash( 'sha256', $relative_directory ),
		'directory_label'        => '' === $relative_directory ? 'uploads/' : 'uploads/' . trailingslashit( $relative_directory ),
	);
}

/**
 * Build one raw metadata-backed historical provenance anchor.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $size_name Raw metadata size key.
 * @param array  $size_data Raw metadata size value.
 * @param string $thumb_path Exact metadata-generated path.
 * @param int    $metadata_meta_id Exact raw metadata row ID.
 * @param int    $selection_meta_id Optional selected attached-file row ID.
 * @param array  $job_payload Immutable job policy/scope.
 * @return array|WP_Error
 */
function yotm_build_historical_metadata_anchor( $attachment_id, $size_name, $size_data, $thumb_path, $metadata_meta_id, $selection_meta_id, $job_payload ) {
	$policy = yotm_historical_policy_validate( $job_payload );
	if ( is_wp_error( $policy ) ) {
		return $policy;
	}
	$size_name = sanitize_key( $size_name );
	if ( '' === $size_name || isset( $job_payload['sizes'][ $size_name ] ) ) {
		return new WP_Error( 'yotm_historical_anchor_registered', __( 'The metadata size is still registered and cannot anchor historical evidence.', 'thumbnail-manager' ) );
	}
	$scan_bases = is_array( $job_payload['scan_bases'] ?? null ) ? $job_payload['scan_bases'] : array( $job_payload['scan_base'] ?? '' );
	$info       = yotm_legacy_parse_disk_candidate( $thumb_path, $scan_bases );
	if ( is_wp_error( $info ) ) {
		return $info;
	}
	$metadata_filename = $size_data['file'] ?? ( $size_data['filename'] ?? '' );
	$metadata_mime     = sanitize_mime_type( $size_data['mime-type'] ?? '' );
	if (
		! is_string( $metadata_filename )
		|| ! hash_equals( $info['filename'], wp_basename( $metadata_filename ) )
		|| absint( $size_data['width'] ?? 0 ) !== $info['width']
		|| absint( $size_data['height'] ?? 0 ) !== $info['height']
		|| '' === $metadata_mime
		|| ! hash_equals( $metadata_mime, $info['mime'] )
	) {
		return new WP_Error( 'yotm_historical_anchor_metadata_mismatch', __( 'Raw metadata does not match the historical anchor file.', 'thumbnail-manager' ) );
	}
	$snapshot = yotm_regenerate_preflight( $attachment_id );
	if ( is_wp_error( $snapshot ) || absint( $snapshot['metadata_meta_id'] ?? 0 ) !== absint( $metadata_meta_id ) ) {
		return is_wp_error( $snapshot ) ? $snapshot : new WP_Error( 'yotm_historical_anchor_raw_changed', __( 'Raw attachment metadata changed while historical evidence was collected.', 'thumbnail-manager' ) );
	}
	$source = (string) ( $snapshot['source'] ?? '' );
	if (
		! hash_equals( $info['directory'], dirname( $source ) )
		|| ! hash_equals( $info['stem'], pathinfo( $source, PATHINFO_FILENAME ) )
		|| ! yotm_is_path_inside_any_dir( $source, $scan_bases )
	) {
		return new WP_Error( 'yotm_historical_anchor_family_mismatch', __( 'The historical anchor does not match the authoritative generation-source family.', 'thumbnail-manager' ) );
	}
	$owners = yotm_media_reference_path_owners( $info['path'] );
	if ( is_wp_error( $owners ) || ! empty( $owners['protected'] ) || 1 !== count( $owners['generated'] ?? array() ) ) {
		return is_wp_error( $owners ) ? $owners : new WP_Error( 'yotm_historical_anchor_owner_ambiguous', __( 'The historical anchor does not have one exact metadata owner.', 'thumbnail-manager' ) );
	}
	$owner = $owners['generated'][0];
	if (
		absint( $owner['attachment_id'] ?? 0 ) !== absint( $attachment_id )
		|| ! hash_equals( $size_name, sanitize_key( $owner['size'] ?? '' ) )
		|| ! hash_equals( $info['filename'], wp_basename( (string) ( $owner['filename'] ?? '' ) ) )
	) {
		return new WP_Error( 'yotm_historical_anchor_owner_ambiguous', __( 'The historical anchor owner does not match raw metadata.', 'thumbnail-manager' ) );
	}
	if ( 'attached_meta_v2' === ( $job_payload['selector'] ?? '' ) ) {
		$authorized = yotm_authorize_attached_file_selector_scope(
			$attachment_id,
			$selection_meta_id,
			absint( $job_payload['selection_meta_max'] ?? 0 ),
			(array) ( $job_payload['selection_subpaths'] ?? array() )
		);
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}
	}
	$source_image = wp_getimagesize( $source );
	if ( ! is_array( $source_image ) || empty( $source_image[0] ) || empty( $source_image[1] ) ) {
		return new WP_Error( 'yotm_historical_anchor_source_dimensions', __( 'The historical anchor source dimensions are unavailable.', 'thumbnail-manager' ) );
	}
	$current_matches = yotm_legacy_matching_size_names(
		array_keys( (array) ( $job_payload['sizes'] ?? array() ) ),
		(array) ( $job_payload['sizes'] ?? array() ),
		$source_image[0],
		$source_image[1],
		$info['width'],
		$info['height']
	);
	if ( ! empty( $current_matches ) ) {
		return new WP_Error( 'yotm_historical_current_projection', __( 'Current registered size evidence vetoes historical classification.', 'thumbnail-manager' ) );
	}
	$live_matches = yotm_historical_live_projection_names(
		$source_image[0],
		$source_image[1],
		$info['width'],
		$info['height']
	);
	if ( ! empty( $live_matches ) ) {
		return new WP_Error( 'yotm_historical_current_projection', __( 'A live registered size projection vetoes historical classification.', 'thumbnail-manager' ) );
	}
	$witness = yotm_historical_witness_fields(
		'anchor',
		$attachment_id,
		$source,
		(string) ( $snapshot['source_hash'] ?? '' ),
		$source_image[0],
		$source_image[1],
		$info,
		$job_payload
	);
	if ( is_wp_error( $witness ) ) {
		return $witness;
	}

	return array_merge(
		$witness,
		array(
			'evidence_kind'              => 'historical_anchor_v1',
			'historical_size_key'        => $size_name,
			'attachment_id'              => absint( $attachment_id ),
			'path'                       => $info['path'],
			'source_path'                => $source,
			'source_path_hash'           => hash( 'sha256', $source ),
			'source_file_hash'           => (string) ( $snapshot['source_hash'] ?? '' ),
			'source_width'               => absint( $source_image[0] ),
			'source_height'              => absint( $source_image[1] ),
			'observed_width'             => $info['width'],
			'observed_height'            => $info['height'],
			'observed_mime'              => $info['mime'],
			'candidate_file_hash'        => $info['file_hash'],
			'candidate_node_fingerprint' => $info['fingerprint'],
			'metadata_meta_id'           => absint( $metadata_meta_id ),
			'selection_meta_id'          => absint( $selection_meta_id ),
		)
	);
}

/**
 * Collect bounded raw metadata provenance anchors for historical cohorts.
 *
 * @param int[] $ids Attachment IDs.
 * @param array $job_payload Immutable job policy/scope.
 * @param int[] $selection_meta_ids Exact attached-file row IDs keyed by attachment.
 * @return array{anchors:array,errors:int,overflow:int}
 */
function yotm_collect_historical_metadata_anchors_for_ids( $ids, $job_payload, $selection_meta_ids = array() ) {
	$result = array(
		'anchors'  => array(),
		'errors'   => 0,
		'overflow' => 0,
	);
	if ( is_wp_error( yotm_historical_policy_validate( $job_payload ) ) ) {
		return $result;
	}
	$raw_batch = yotm_media_reference_raw_postmeta_rows_batch( $ids, array( '_wp_attached_file', '_wp_attachment_metadata' ) );
	if ( is_wp_error( $raw_batch ) ) {
		$result['errors'] = count( (array) $ids );
		return $result;
	}
	$uploads   = wp_get_upload_dir();
	$base      = (string) ( $uploads['basedir'] ?? '' );
	$constants = yotm_historical_cohort_constants();
	$limit     = absint( $constants['max_anchor_size_keys'] ?? 100 );

	foreach ( (array) $ids as $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$attached_rows = $raw_batch[ $attachment_id ]['_wp_attached_file'] ?? array();
		$metadata_rows = $raw_batch[ $attachment_id ]['_wp_attachment_metadata'] ?? array();
		if (
			! $attachment_id
			|| 1 !== count( $attached_rows )
			|| 1 !== count( $metadata_rows )
			|| ! is_array( $metadata_rows[0]['value'] ?? null )
		) {
			++$result['errors'];
			continue;
		}
		$metadata = $metadata_rows[0]['value'];
		$sizes    = is_array( $metadata['sizes'] ?? null ) ? $metadata['sizes'] : array();
		$unknown  = array_diff_key( $sizes, (array) ( $job_payload['sizes'] ?? array() ) );
		if ( count( $unknown ) > $limit ) {
			++$result['overflow'];
			continue;
		}
		$raw_file = is_string( $metadata['file'] ?? null ) ? $metadata['file'] : ( $attached_rows[0]['value'] ?? '' );
		if ( ! is_string( $raw_file ) || '' === $raw_file ) {
			++$result['errors'];
			continue;
		}
		$full = preg_match( '#^(?:[A-Za-z]:)?/#', $raw_file ) ? $raw_file : trailingslashit( $base ) . ltrim( $raw_file, '/\\' );
		$full = yotm_media_source_canonical_path( $full );
		if ( is_wp_error( $full ) ) {
			++$result['errors'];
			continue;
		}
		$directory = dirname( $full );
		foreach ( $unknown as $size_name => $size_data ) {
			$filename = is_array( $size_data ) ? ( $size_data['file'] ?? ( $size_data['filename'] ?? '' ) ) : '';
			if ( ! is_string( $filename ) || '' === $filename || wp_basename( $filename ) !== $filename ) {
				++$result['errors'];
				continue;
			}
			$anchor = yotm_build_historical_metadata_anchor(
				$attachment_id,
				(string) $size_name,
				$size_data,
				trailingslashit( $directory ) . $filename,
				absint( $metadata_rows[0]['meta_id'] ?? 0 ),
				absint( $selection_meta_ids[ $attachment_id ] ?? 0 ),
				$job_payload
			);
			if ( is_wp_error( $anchor ) ) {
				++$result['errors'];
				continue;
			}
			$result['anchors'][ $anchor['historical_witness_key'] ] = $anchor;
		}//end foreach
	}//end foreach
	ksort( $result['anchors'] );
	return $result;
}

/**
 * Reduce historical witnesses with an order-independent diversity-preserving rule.
 *
 * @param array $witnesses Witnesses.
 * @param int   $minimum_family_count Minimum family count for this evidence class.
 * @return array
 */
function yotm_historical_reduce_witness_pool( $witnesses, $minimum_family_count ) {
	$by_key = array();
	foreach ( (array) $witnesses as $witness ) {
		$key = is_array( $witness ) ? (string) ( $witness['historical_witness_key'] ?? '' ) : '';
		if ( preg_match( '/^[a-f0-9]{64}$/', $key ) ) {
			$by_key[ $key ] = $witness;
		}
	}
	ksort( $by_key );
	$selected = array_slice( $by_key, 0, max( 1, absint( $minimum_family_count ) ), true );
	foreach ( array(
		'source_ratio_key' => 3,
		'directory_key'    => 2,
	) as $field => $bucket_limit ) {
		$buckets = array();
		foreach ( $by_key as $key => $witness ) {
			$bucket = (string) ( $witness[ $field ] ?? '' );
			if ( '' !== $bucket && ! isset( $buckets[ $bucket ] ) ) {
				$buckets[ $bucket ] = $key;
			}
		}
		ksort( $buckets );
		foreach ( array_slice( $buckets, 0, $bucket_limit, true ) as $key ) {
			$selected[ $key ] = $by_key[ $key ];
		}
	}
	ksort( $selected );
	$limit = absint( yotm_historical_cohort_constants()['max_witness_pool'] ?? 10 );
	return array_values( array_slice( $selected, 0, $limit, true ) );
}

/**
 * Return deterministic combinations from one bounded witness pool.
 *
 * @param array $items Items.
 * @param int   $count Combination size.
 * @return array
 */
function yotm_historical_combinations( $items, $count ) {
	$items = array_values( (array) $items );
	$count = absint( $count );
	if ( 0 === $count ) {
		return array( array() );
	}
	if ( count( $items ) < $count ) {
		return array();
	}
	$out = array();
	foreach ( $items as $index => $item ) {
		$remaining = array_slice( $items, $index + 1 );
		foreach ( yotm_historical_combinations( $remaining, $count - 1 ) as $tail ) {
			$out[] = array_merge( array( $item ), $tail );
		}
	}
	return $out;
}

/**
 * Build the deterministic sealed cohort proof from bounded witness pools.
 *
 * @param array  $anchors Anchor witnesses.
 * @param array  $observations Disk witnesses.
 * @param string $historical_size_key Absent historical metadata size key.
 * @param string $policy_hash Exact policy hash.
 * @return array|WP_Error
 */
function yotm_historical_seal_cohort( $anchors, $observations, $historical_size_key, $policy_hash ) {
	$constants = yotm_historical_cohort_constants();
	$anchors   = yotm_historical_reduce_witness_pool( $anchors, $constants['min_metadata_anchors'] );
	$observed  = yotm_historical_reduce_witness_pool( $observations, $constants['min_disk_observations'] );
	$best      = null;
	$best_hash = '';

	foreach ( yotm_historical_combinations( $anchors, $constants['min_metadata_anchors'] ) as $anchor_set ) {
		foreach ( yotm_historical_combinations( $observed, $constants['min_disk_observations'] ) as $observation_set ) {
			$members        = array_merge( $anchor_set, $observation_set );
			$families       = array();
			$attachment_ids = array();
			$source_paths   = array();
			$source_files   = array();
			$ratios         = array();
			$directories    = array();
			$signatures     = array();
			foreach ( $members as $member ) {
				$families[ (string) ( $member['historical_family_key'] ?? '' ) ]  = true;
				$attachment_ids[ absint( $member['attachment_id'] ?? 0 ) ]        = true;
				$source_paths[ (string) ( $member['source_path_hash'] ?? '' ) ]   = true;
				$source_files[ (string) ( $member['source_file_hash'] ?? '' ) ]   = true;
				$ratios[ (string) ( $member['source_ratio_key'] ?? '' ) ]         = true;
				$directories[ (string) ( $member['directory_key'] ?? '' ) ]       = true;
				$signatures[ (string) ( $member['historical_signature'] ?? '' ) ] = true;
			}
			unset( $families[''], $attachment_ids[0], $source_paths[''], $source_files[''], $ratios[''], $directories[''], $signatures[''] );
			if (
				count( $families ) < $constants['min_families']
				|| count( $attachment_ids ) < $constants['min_families']
				|| count( $source_paths ) < $constants['min_families']
				|| count( $source_files ) < $constants['min_families']
				|| count( $ratios ) < $constants['min_ratio_buckets']
				|| count( $directories ) < $constants['min_directories']
				|| 1 !== count( $signatures )
			) {
				continue;
			}
			usort(
				$members,
				static function ( $left, $right ) {
					return strcmp( (string) $left['historical_witness_key'], (string) $right['historical_witness_key'] );
				}
			);
			usort(
				$anchor_set,
				static function ( $left, $right ) {
					return strcmp( (string) $left['historical_witness_key'], (string) $right['historical_witness_key'] );
				}
			);
			$summaries = array();
			foreach ( $members as $member ) {
				$summaries[] = array(
					'kind'             => (string) ( $member['evidence_kind'] ?? $member['ownership_schema'] ?? '' ),
					'witness_key'      => (string) ( $member['historical_witness_key'] ?? '' ),
					'family_key'       => (string) ( $member['historical_family_key'] ?? '' ),
					'attachment_id'    => absint( $member['attachment_id'] ?? 0 ),
					'source_path_hash' => (string) ( $member['source_path_hash'] ?? '' ),
					'source_file_hash' => (string) ( $member['source_file_hash'] ?? '' ),
					'source_ratio_key' => (string) ( $member['source_ratio_key'] ?? '' ),
					'directory_key'    => (string) ( $member['directory_key'] ?? '' ),
				);
			}
			$candidate = array(
				'version'             => 1,
				'historical_size_key' => sanitize_key( $historical_size_key ),
				'signature'           => (string) array_key_first( $signatures ),
				'policy_hash'         => (string) $policy_hash,
				'constants'           => $constants,
				'ratio_schema'        => 'integer_gcd_v1',
				'anchor_evidence'     => array_values( $anchor_set ),
				'witnesses'           => $summaries,
			);
			$digest    = hash( 'sha256', (string) wp_json_encode( $candidate ) );
			if ( null === $best || strcmp( $digest, $best_hash ) < 0 ) {
				$best      = $candidate;
				$best_hash = $digest;
			}
		}//end foreach
	}//end foreach
	if ( null === $best ) {
		return new WP_Error( 'yotm_historical_cohort_insufficient', __( 'The historical cohort does not meet the reviewed confidence threshold.', 'thumbnail-manager' ) );
	}
	$best['digest'] = $best_hash;
	return $best;
}

/**
 * Classify a bounded set of strict disk paths using exact authoritative state.
 *
 * @param string[] $paths Candidate paths.
 * @param array    $job_payload Immutable Prune policy/scope payload.
 * @return array<string,array|WP_Error>|WP_Error Results keyed by normalized path.
 */
function yotm_classify_legacy_disk_candidates( $paths, $job_payload ) {
	$policy = yotm_legacy_policy_validate( $job_payload );
	if ( is_wp_error( $policy ) ) {
		return $policy;
	}
	$clean = yotm_media_reference_require_complete_index();
	if ( is_wp_error( $clean ) ) {
		return $clean;
	}
	$scan_bases       = is_array( $job_payload['scan_bases'] ?? null ) ? $job_payload['scan_bases'] : array( $job_payload['scan_base'] ?? '' );
	$parsed           = array();
	$results          = array();
	$source_map       = array();
	$all_hashes       = array();
	$candidate_hashes = array();
	$extensions       = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' );

	foreach ( array_slice( array_values( array_unique( array_map( 'strval', (array) $paths ) ) ), 0, 1000 ) as $path ) {
		$key  = yotm_prune_journal_lexical_path( $path );
		$info = yotm_legacy_parse_disk_candidate( $key, $scan_bases );
		if ( is_wp_error( $info ) ) {
			$results[ $key ] = $info;
			continue;
		}
		$parsed[ $key ]                          = $info;
		$candidate_hashes[ $key ]                = hash( 'sha256', $info['path'] );
		$all_hashes[ $candidate_hashes[ $key ] ] = $candidate_hashes[ $key ];
		foreach ( $extensions as $extension ) {
			$source = yotm_media_source_canonical_path( $info['directory'] . '/' . $info['stem'] . '.' . $extension );
			if ( is_wp_error( $source ) ) {
				continue;
			}
			$hash                        = hash( 'sha256', $source );
			$source_map[ $key ][ $hash ] = $source;
			$all_hashes[ $hash ]         = $hash;
		}
	}

	$rows_by_hash = yotm_media_source_store_paths_rows( array_values( $all_hashes ), YOTM_MEDIA_SOURCE_FANOUT_LIMIT );
	if ( is_wp_error( $rows_by_hash ) ) {
		return $rows_by_hash;
	}
	$preflights         = array();
	$source_owner_cache = array();
	foreach ( $parsed as $key => $info ) {
		$family_ids = array();
		foreach ( (array) ( $source_map[ $key ] ?? array() ) as $hash => $source_path ) {
			foreach ( (array) ( $rows_by_hash[ $hash ] ?? array() ) as $row ) {
				$kind = sanitize_key( $row->source_kind ?? '' );
				if ( in_array( $kind, array( 'attached', 'metadata_full', 'original' ), true ) && hash_equals( $source_path, (string) $row->path ) ) {
					$family_ids[ absint( $row->attachment_id ?? 0 ) ] = absint( $row->attachment_id ?? 0 );
				}
			}
		}
		$family_ids = array_values( array_filter( $family_ids ) );
		if ( 1 !== count( $family_ids ) ) {
			$results[ $key ] = new WP_Error( 'yotm_legacy_family_ambiguous', __( 'The disk-only file could not be bound to exactly one authoritative attachment family.', 'thumbnail-manager' ) );
			continue;
		}

		$attachment_id = $family_ids[0];
		if ( ! array_key_exists( $attachment_id, $preflights ) ) {
			$preflights[ $attachment_id ] = yotm_regenerate_preflight( $attachment_id );
		}
		$snapshot = $preflights[ $attachment_id ];
		if ( is_wp_error( $snapshot ) ) {
			$results[ $key ] = $snapshot;
			continue;
		}
		$source = (string) ( $snapshot['source'] ?? '' );
		if (
			! isset( $source_map[ $key ][ hash( 'sha256', $source ) ] )
			|| ! hash_equals( $info['directory'], dirname( $source ) )
			|| ! hash_equals( $info['stem'], pathinfo( $source, PATHINFO_FILENAME ) )
			|| ! yotm_is_path_inside_any_dir( $source, $scan_bases )
		) {
			$results[ $key ] = new WP_Error( 'yotm_legacy_generation_source_mismatch', __( 'The inferred disk family does not match the authoritative generation source.', 'thumbnail-manager' ) );
			continue;
		}

		if ( ! array_key_exists( $source, $source_owner_cache ) ) {
			$source_owner_cache[ $source ] = yotm_media_reference_path_owners( $source );
		}
		$source_owners = $source_owner_cache[ $source ];
		if ( is_wp_error( $source_owners ) ) {
			$results[ $key ] = $source_owners;
			continue;
		}
		$source_owned = false;
		foreach ( (array) $source_owners['protected'] as $owner ) {
			if ( absint( $owner['attachment_id'] ?? 0 ) === $attachment_id ) {
				$source_owned = true;
				break;
			}
		}
		if ( ! $source_owned ) {
			$results[ $key ] = new WP_Error( 'yotm_legacy_source_unowned', __( 'The authoritative generation source is not owned by the matched attachment.', 'thumbnail-manager' ) );
			continue;
		}

		if ( 'attached_meta_v2' === ( $job_payload['selector'] ?? '' ) ) {
			$authorized = yotm_authorize_attached_file_selector_scope(
				$attachment_id,
				absint( $snapshot['attached_meta_id'] ?? 0 ),
				absint( $job_payload['selection_meta_max'] ?? 0 ),
				(array) ( $job_payload['selection_subpaths'] ?? array() )
			);
			if ( is_wp_error( $authorized ) ) {
				$results[ $key ] = $authorized;
				continue;
			}
		}

		$source_image = wp_getimagesize( $source );
		if ( ! is_array( $source_image ) || empty( $source_image[0] ) || empty( $source_image[1] ) ) {
			$results[ $key ] = new WP_Error( 'yotm_legacy_source_dimensions', __( 'The authoritative generation-source dimensions could not be decoded.', 'thumbnail-manager' ) );
			continue;
		}
		$sizes          = is_array( $job_payload['sizes'] ?? null ) ? $job_payload['sizes'] : array();
		$matched_keep   = yotm_legacy_matching_size_names( $job_payload['keep'] ?? array(), $sizes, $source_image[0], $source_image[1], $info['width'], $info['height'] );
		$matched_remove = yotm_legacy_matching_size_names( $job_payload['remove'] ?? array(), $sizes, $source_image[0], $source_image[1], $info['width'], $info['height'] );
		if ( ! empty( $matched_keep ) ) {
			$results[ $key ] = new WP_Error( 'yotm_legacy_kept_dimension', __( 'The disk-only dimensions also match a size selected to keep.', 'thumbnail-manager' ) );
			continue;
		}

		$candidate_rows = (array) ( $rows_by_hash[ $candidate_hashes[ $key ] ] ?? array() );
		if ( ! empty( $candidate_rows ) ) {
			foreach ( $candidate_rows as $candidate_row ) {
				if ( ! hash_equals( $info['path'], (string) $candidate_row->path ) ) {
					$results[ $key ] = new WP_Error( 'yotm_legacy_path_hash_collision', __( 'The disk-only path hash matched a different authoritative path.', 'thumbnail-manager' ) );
					continue 2;
				}
			}
			$owners = yotm_media_reference_path_owners( $info['path'] );
			if ( is_wp_error( $owners ) ) {
				$results[ $key ] = $owners;
				continue;
			}
			if ( ! empty( $owners['protected'] ) || ! empty( $owners['generated'] ) ) {
				$results[ $key ] = new WP_Error( 'yotm_legacy_path_owned', __( 'The disk-only path now has an authoritative media reference.', 'thumbnail-manager' ) );
				continue;
			}
		}
		if ( empty( $matched_remove ) ) {
			$historical_policy = yotm_historical_policy_validate( $job_payload );
			if ( is_wp_error( $historical_policy ) ) {
				$results[ $key ] = new WP_Error( 'yotm_legacy_unregistered_dimension', __( 'The disk-only dimensions do not match a currently registered removed size.', 'thumbnail-manager' ) );
				continue;
			}
			$live_matches = yotm_historical_live_projection_names(
				$source_image[0],
				$source_image[1],
				$info['width'],
				$info['height']
			);
			if ( ! empty( $live_matches ) ) {
				$results[ $key ] = new WP_Error( 'yotm_historical_current_projection', __( 'A live registered size projection vetoes historical classification.', 'thumbnail-manager' ) );
				continue;
			}
			$witness = yotm_historical_witness_fields(
				'observation',
				$attachment_id,
				$source,
				(string) ( $snapshot['source_hash'] ?? '' ),
				$source_image[0],
				$source_image[1],
				$info,
				$job_payload
			);
			if ( is_wp_error( $witness ) ) {
				$results[ $key ] = $witness;
				continue;
			}
			$results[ $key ] = array_merge(
				$witness,
				array(
					'path'                       => $info['path'],
					'attachment_id'              => $attachment_id,
					'size'                       => '',
					'source'                     => 'historical_observation',
					'ownership_schema'           => 'historical_observation_v1',
					'ownership'                  => 'historical_observation',
					'ownership_evidence'         => array(),
					'remove_metadata'            => 0,
					'metadata_refs'              => array(),
					'evidence_version'           => 1,
					'legacy_policy_hash'         => (string) ( $job_payload['legacy_policy']['hash'] ?? '' ),
					'source_path'                => $source,
					'source_path_hash'           => hash( 'sha256', $source ),
					'source_file_hash'           => (string) $snapshot['source_hash'],
					'selection_meta_id'          => 'attached_meta_v2' === ( $job_payload['selector'] ?? '' ) ? absint( $snapshot['attached_meta_id'] ?? 0 ) : 0,
					'source_width'               => absint( $source_image[0] ),
					'source_height'              => absint( $source_image[1] ),
					'observed_width'             => $info['width'],
					'observed_height'            => $info['height'],
					'observed_mime'              => $info['mime'],
					'matched_remove_sizes'       => array(),
					'estimated_bytes'            => $info['bytes'],
					'candidate_file_hash'        => $info['file_hash'],
					'candidate_node_fingerprint' => $info['fingerprint'],
				)
			);
			continue;
		}//end if
		$current_enabled = 1 === absint( $job_payload['legacy_policy']['version'] ?? 0 )
			? ! empty( $job_payload['legacy_policy']['enabled'] )
			: ! empty( $job_payload['legacy_policy']['current_disabled_enabled'] );
		if ( ! $current_enabled ) {
			$results[ $key ] = new WP_Error( 'yotm_legacy_current_disabled_not_enabled', __( 'Current-disabled legacy discovery is not enabled for this job.', 'thumbnail-manager' ) );
			continue;
		}

		$policy_hash     = (string) ( $job_payload['legacy_policy']['hash'] ?? '' );
		$proof           = array(
			'attachment_id'     => $attachment_id,
			'size'              => implode( ',', $matched_remove ),
			'filename'          => $info['filename'],
			'mime'              => $info['mime'],
			'selection'         => 'legacy_generated',
			'selection_meta_id' => 'attached_meta_v2' === ( $job_payload['selector'] ?? '' ) ? absint( $snapshot['attached_meta_id'] ?? 0 ) : 0,
		);
		$results[ $key ] = array(
			'path'                       => $info['path'],
			'attachment_id'              => $attachment_id,
			'size'                       => implode( ',', $matched_remove ),
			'source'                     => 'legacy_disk',
			'ownership_schema'           => 'legacy_generated_v1',
			'ownership'                  => 'legacy_disk',
			'ownership_evidence'         => array( $proof ),
			'remove_metadata'            => 0,
			'metadata_refs'              => array(),
			'evidence_version'           => 1,
			'legacy_policy_hash'         => $policy_hash,
			'source_path'                => $source,
			'source_path_hash'           => hash( 'sha256', $source ),
			'source_file_hash'           => (string) $snapshot['source_hash'],
			'source_width'               => absint( $source_image[0] ),
			'source_height'              => absint( $source_image[1] ),
			'observed_width'             => $info['width'],
			'observed_height'            => $info['height'],
			'observed_mime'              => $info['mime'],
			'matched_remove_sizes'       => $matched_remove,
			'estimated_bytes'            => $info['bytes'],
			'candidate_file_hash'        => $info['file_hash'],
			'candidate_node_fingerprint' => $info['fingerprint'],
		);
	}//end foreach

	return $results;
}

/**
 * Rebuild and compare every class-specific legacy authorization fact.
 *
 * @param array $item Immutable item payload.
 * @param array $job_payload Immutable job policy payload.
 * @return true|WP_Error
 */
function yotm_prune_validate_legacy_evidence( $item, $job_payload ) {
	if (
		! is_array( $item )
		|| 'legacy_generated_v1' !== ( $item['ownership_schema'] ?? '' )
		|| 'legacy_disk' !== ( $item['ownership'] ?? '' )
		|| 1 !== absint( $item['evidence_version'] ?? 0 )
		|| ! empty( $item['remove_metadata'] )
		|| ! empty( $item['metadata_refs'] )
	) {
		return new WP_Error( 'yotm_legacy_evidence_invalid', __( 'The legacy prune item lacks exact disk-only evidence.', 'thumbnail-manager' ) );
	}
	$classified = yotm_classify_legacy_disk_candidates( array( $item['path'] ?? '' ), $job_payload );
	if ( is_wp_error( $classified ) ) {
		return $classified;
	}
	$key     = yotm_prune_journal_lexical_path( $item['path'] ?? '' );
	$current = $classified[ $key ] ?? null;
	if ( is_wp_error( $current ) ) {
		return $current;
	}
	if ( ! is_array( $current ) ) {
		return new WP_Error( 'yotm_legacy_evidence_changed', __( 'The legacy disk-only evidence could not be reproduced.', 'thumbnail-manager' ) );
	}
	$fields = array(
		'path',
		'attachment_id',
		'size',
		'source',
		'ownership_schema',
		'ownership',
		'ownership_evidence',
		'remove_metadata',
		'metadata_refs',
		'evidence_version',
		'legacy_policy_hash',
		'source_path',
		'source_path_hash',
		'source_file_hash',
		'source_width',
		'source_height',
		'observed_width',
		'observed_height',
		'observed_mime',
		'matched_remove_sizes',
		'estimated_bytes',
		'candidate_file_hash',
		'candidate_node_fingerprint',
	);
	foreach ( $fields as $field ) {
		if ( ( $item[ $field ] ?? null ) !== ( $current[ $field ] ?? null ) ) {
			return new WP_Error( 'yotm_legacy_evidence_changed', __( 'The legacy disk-only evidence changed after scanning.', 'thumbnail-manager' ) );
		}
	}

	return true;
}

/**
 * Rebuild one persisted raw metadata anchor from current authoritative state.
 *
 * @param array $anchor Persisted anchor.
 * @param array $job_payload Job payload.
 * @return array|WP_Error
 */
function yotm_revalidate_historical_anchor( $anchor, $job_payload ) {
	$attachment_id = absint( $anchor['attachment_id'] ?? 0 );
	$size_key      = sanitize_key( $anchor['historical_size_key'] ?? '' );
	$metadata_rows = yotm_media_reference_raw_postmeta_rows( $attachment_id, '_wp_attachment_metadata' );
	if ( is_wp_error( $metadata_rows ) || 1 !== count( $metadata_rows ) || ! is_array( $metadata_rows[0]['value'] ?? null ) ) {
		return is_wp_error( $metadata_rows ) ? $metadata_rows : new WP_Error( 'yotm_historical_anchor_raw_changed', __( 'Raw historical anchor metadata is ambiguous or changed.', 'thumbnail-manager' ) );
	}
	$metadata = $metadata_rows[0]['value'];
	if ( '' === $size_key || ! is_array( $metadata['sizes'][ $size_key ] ?? null ) ) {
		return new WP_Error( 'yotm_historical_anchor_raw_changed', __( 'The historical anchor metadata reference no longer exists.', 'thumbnail-manager' ) );
	}
	$current = yotm_build_historical_metadata_anchor(
		$attachment_id,
		$size_key,
		$metadata['sizes'][ $size_key ],
		(string) ( $anchor['path'] ?? '' ),
		absint( $metadata_rows[0]['meta_id'] ?? 0 ),
		absint( $anchor['selection_meta_id'] ?? 0 ),
		$job_payload
	);
	if ( is_wp_error( $current ) ) {
		return $current;
	}
	$fields = array(
		'historical_signature',
		'historical_family_key',
		'historical_witness_key',
		'source_ratio_key',
		'output_ratio_key',
		'directory_key',
		'historical_size_key',
		'attachment_id',
		'path',
		'source_path_hash',
		'source_file_hash',
		'source_width',
		'source_height',
		'observed_width',
		'observed_height',
		'observed_mime',
		'candidate_file_hash',
		'candidate_node_fingerprint',
		'metadata_meta_id',
		'selection_meta_id',
	);
	foreach ( $fields as $field ) {
		if ( ( $anchor[ $field ] ?? null ) !== ( $current[ $field ] ?? null ) ) {
			return new WP_Error( 'yotm_historical_anchor_changed', __( 'Historical metadata-anchor evidence changed before sealing.', 'thumbnail-manager' ) );
		}
	}
	return $current;
}

/**
 * Rebuild one persisted historical disk observation.
 *
 * @param array $observation Persisted observation.
 * @param array $job_payload Job payload.
 * @return array|WP_Error
 */
function yotm_revalidate_historical_observation( $observation, $job_payload ) {
	$classified = yotm_classify_legacy_disk_candidates( array( $observation['path'] ?? '' ), $job_payload );
	if ( is_wp_error( $classified ) ) {
		return $classified;
	}
	$key     = yotm_prune_journal_lexical_path( $observation['path'] ?? '' );
	$current = $classified[ $key ] ?? null;
	if ( is_wp_error( $current ) ) {
		return $current;
	}
	if ( ! is_array( $current ) || 'historical_observation_v1' !== ( $current['ownership_schema'] ?? '' ) ) {
		return new WP_Error( 'yotm_historical_observation_changed', __( 'The historical disk observation can no longer be reproduced.', 'thumbnail-manager' ) );
	}
	$fields = array(
		'path',
		'attachment_id',
		'ownership_schema',
		'historical_signature',
		'historical_family_key',
		'historical_witness_key',
		'source_ratio_key',
		'output_ratio_key',
		'directory_key',
		'selection_meta_id',
		'source_path',
		'source_path_hash',
		'source_file_hash',
		'source_width',
		'source_height',
		'observed_width',
		'observed_height',
		'observed_mime',
		'candidate_file_hash',
		'candidate_node_fingerprint',
	);
	foreach ( $fields as $field ) {
		if ( ( $observation[ $field ] ?? null ) !== ( $current[ $field ] ?? null ) ) {
			return new WP_Error( 'yotm_historical_observation_changed', __( 'Historical disk evidence changed after scanning.', 'thumbnail-manager' ) );
		}
	}
	return $current;
}

/**
 * Verify structural and hash integrity of one sealed cohort proof.
 *
 * @param array  $proof Cohort proof.
 * @param string $policy_hash Exact job policy hash.
 * @return true|WP_Error
 */
function yotm_validate_historical_cohort_proof( $proof, $policy_hash ) {
	if ( ! is_array( $proof ) || 1 !== absint( $proof['version'] ?? 0 ) || 'integer_gcd_v1' !== ( $proof['ratio_schema'] ?? '' ) ) {
		return new WP_Error( 'yotm_historical_cohort_invalid', __( 'The historical cohort proof is missing or unsupported.', 'thumbnail-manager' ) );
	}
	$historical_size_key = (string) ( $proof['historical_size_key'] ?? '' );
	$signature           = (string) ( $proof['signature'] ?? '' );
	if (
		'' === $historical_size_key
		|| sanitize_key( $historical_size_key ) !== $historical_size_key
		|| ! preg_match( '/^[1-9][0-9]*x[1-9][0-9]*:[a-z0-9.+-]+\/[a-z0-9.+-]+$/', $signature )
	) {
		return new WP_Error( 'yotm_historical_cohort_invalid', __( 'The historical cohort identity is malformed.', 'thumbnail-manager' ) );
	}
	if ( ! hash_equals( (string) $policy_hash, (string) ( $proof['policy_hash'] ?? '' ) ) || yotm_historical_cohort_constants() !== ( $proof['constants'] ?? null ) ) {
		return new WP_Error( 'yotm_historical_cohort_policy_changed', __( 'The historical cohort policy changed.', 'thumbnail-manager' ) );
	}
	$digest = (string) ( $proof['digest'] ?? '' );
	$copy   = $proof;
	unset( $copy['digest'] );
	if ( ! preg_match( '/^[a-f0-9]{64}$/', $digest ) || ! hash_equals( $digest, hash( 'sha256', (string) wp_json_encode( $copy ) ) ) ) {
		return new WP_Error( 'yotm_historical_cohort_hash_changed', __( 'The sealed historical cohort evidence changed.', 'thumbnail-manager' ) );
	}
	$witnesses           = is_array( $proof['witnesses'] ?? null ) ? $proof['witnesses'] : array();
	$anchor_evidence     = is_array( $proof['anchor_evidence'] ?? null ) ? $proof['anchor_evidence'] : array();
	$anchors             = 0;
	$observations        = 0;
	$witness_keys        = array();
	$families            = array();
	$attachment_ids      = array();
	$source_paths        = array();
	$source_files        = array();
	$ratios              = array();
	$directories         = array();
	$anchor_keys         = array();
	$witness_anchor_keys = array();
	foreach ( $anchor_evidence as $anchor ) {
		if ( ! is_array( $anchor ) || 'historical_anchor_v1' !== ( $anchor['evidence_kind'] ?? '' ) ) {
			return new WP_Error( 'yotm_historical_cohort_invalid', __( 'The historical cohort anchor evidence is malformed.', 'thumbnail-manager' ) );
		}
		$key = (string) ( $anchor['historical_witness_key'] ?? '' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $key ) ) {
			return new WP_Error( 'yotm_historical_cohort_invalid', __( 'The historical cohort anchor identity is malformed.', 'thumbnail-manager' ) );
		}
		$anchor_keys[ $key ] = true;
	}
	foreach ( $witnesses as $witness ) {
		$kind = (string) ( $witness['kind'] ?? '' );
		if ( 'historical_anchor_v1' === $kind ) {
			++$anchors;
			$witness_anchor_keys[ (string) ( $witness['witness_key'] ?? '' ) ] = true;
		} elseif ( 'historical_observation_v1' === $kind ) {
			++$observations;
		} else {
			return new WP_Error( 'yotm_historical_cohort_invalid', __( 'The historical cohort contains an unknown witness.', 'thumbnail-manager' ) );
		}
		$witness_key = (string) ( $witness['witness_key'] ?? '' );
		$family_key  = (string) ( $witness['family_key'] ?? '' );
		$source_path = (string) ( $witness['source_path_hash'] ?? '' );
		$source_file = (string) ( $witness['source_file_hash'] ?? '' );
		$directory   = (string) ( $witness['directory_key'] ?? '' );
		if (
			! preg_match( '/^[a-f0-9]{64}$/', $witness_key )
			|| ! preg_match( '/^[a-f0-9]{64}$/', $family_key )
			|| ! preg_match( '/^[a-f0-9]{64}$/', $source_path )
			|| ! preg_match( '/^[a-f0-9]{64}$/', $source_file )
			|| ! preg_match( '/^[a-f0-9]{64}$/', $directory )
			|| ! preg_match( '/^[1-9][0-9]*:[1-9][0-9]*$/', (string) ( $witness['source_ratio_key'] ?? '' ) )
			|| empty( $witness['attachment_id'] )
		) {
			return new WP_Error( 'yotm_historical_cohort_invalid', __( 'The historical cohort contains malformed witness identity.', 'thumbnail-manager' ) );
		}
		$witness_keys[ $witness_key ]                                    = true;
		$families[ (string) ( $witness['family_key'] ?? '' ) ]           = true;
		$attachment_ids[ absint( $witness['attachment_id'] ?? 0 ) ]      = true;
		$source_paths[ (string) ( $witness['source_path_hash'] ?? '' ) ] = true;
		$source_files[ (string) ( $witness['source_file_hash'] ?? '' ) ] = true;
		$ratios[ (string) ( $witness['source_ratio_key'] ?? '' ) ]       = true;
		$directories[ (string) ( $witness['directory_key'] ?? '' ) ]     = true;
	}//end foreach
	unset( $families[''], $attachment_ids[0], $source_paths[''], $source_files[''], $ratios[''], $directories[''] );
	$constants = yotm_historical_cohort_constants();
	if (
		$anchors !== $constants['min_metadata_anchors']
		|| $observations !== $constants['min_disk_observations']
		|| count( $anchor_evidence ) !== $constants['min_metadata_anchors']
		|| count( $anchor_keys ) !== $constants['min_metadata_anchors']
		|| ! empty( array_diff_key( $anchor_keys, $witness_anchor_keys ) )
		|| ! empty( array_diff_key( $witness_anchor_keys, $anchor_keys ) )
		|| count( $witness_keys ) < $constants['min_families']
		|| count( $families ) < $constants['min_families']
		|| count( $attachment_ids ) < $constants['min_families']
		|| count( $source_paths ) < $constants['min_families']
		|| count( $source_files ) < $constants['min_families']
		|| count( $ratios ) < $constants['min_ratio_buckets']
		|| count( $directories ) < $constants['min_directories']
	) {
		return new WP_Error( 'yotm_historical_cohort_invalid', __( 'The historical cohort does not meet its immutable threshold.', 'thumbnail-manager' ) );
	}
	return true;
}

/**
 * Revalidate every digest-bound metadata anchor in a historical proof.
 *
 * @param array $proof Sealed cohort proof.
 * @param array $job_payload Job payload.
 * @return true|WP_Error
 */
function yotm_revalidate_historical_cohort_anchors( $proof, $job_payload ) {
	$anchors = is_array( $proof['anchor_evidence'] ?? null ) ? $proof['anchor_evidence'] : array();
	if ( count( $anchors ) !== yotm_historical_cohort_constants()['min_metadata_anchors'] ) {
		return new WP_Error( 'yotm_historical_cohort_invalid', __( 'The historical cohort lacks exact metadata anchors.', 'thumbnail-manager' ) );
	}
	foreach ( $anchors as $anchor ) {
		$current = yotm_revalidate_historical_anchor( $anchor, $job_payload );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
	}
	return true;
}

/**
 * Promote an individually verified observation into final historical authority.
 *
 * @param array $observation Observation payload.
 * @param array $proof Sealed cohort proof.
 * @return array|WP_Error
 */
function yotm_build_historical_legacy_item( $observation, $proof ) {
	if (
		! is_array( $observation )
		|| 'historical_observation_v1' !== ( $observation['ownership_schema'] ?? '' )
		|| empty( $observation['attachment_id'] )
		|| empty( $observation['path'] )
		|| ! empty( $observation['remove_metadata'] )
		|| ! empty( $observation['metadata_refs'] )
	) {
		return new WP_Error( 'yotm_historical_observation_invalid', __( 'The historical observation lacks exact disk-only evidence.', 'thumbnail-manager' ) );
	}
	$valid = yotm_validate_historical_cohort_proof( $proof, (string) ( $observation['legacy_policy_hash'] ?? '' ) );
	if ( is_wp_error( $valid ) || ! hash_equals( (string) ( $observation['historical_signature'] ?? '' ), (string) ( $proof['signature'] ?? '' ) ) ) {
		return is_wp_error( $valid ) ? $valid : new WP_Error( 'yotm_historical_signature_changed', __( 'The historical observation does not match the sealed cohort.', 'thumbnail-manager' ) );
	}
	$item                            = $observation;
	$item['size']                    = sanitize_key( $proof['historical_size_key'] ?? '' );
	$item['source']                  = 'historical_legacy_disk';
	$item['ownership_schema']        = 'historical_legacy_generated_v1';
	$item['ownership']               = 'historical_legacy_disk';
	$item['evidence_version']        = 1;
	$item['historical_size_key']     = $item['size'];
	$item['historical_cohort_proof'] = $proof;
	$item['ownership_evidence']      = array(
		array(
			'attachment_id'     => absint( $item['attachment_id'] ?? 0 ),
			'size'              => $item['size'],
			'filename'          => wp_basename( (string) ( $item['path'] ?? '' ) ),
			'mime'              => sanitize_mime_type( $item['observed_mime'] ?? '' ),
			'selection'         => 'historical_legacy',
			'selection_meta_id' => absint( $item['selection_meta_id'] ?? 0 ),
		),
	);
	$item['remove_metadata']         = 0;
	$item['metadata_refs']           = array();
	$item['matched_remove_sizes']    = array();
	return $item;
}

/**
 * Revalidate final historical disk-only authority.
 *
 * @param array $item Final immutable item.
 * @param array $job_payload Job payload.
 * @return true|WP_Error
 */
function yotm_prune_validate_historical_evidence( $item, $job_payload ) {
	$policy = yotm_historical_policy_validate( $job_payload );
	if ( is_wp_error( $policy ) ) {
		return $policy;
	}
	if (
		'historical_legacy_generated_v1' !== ( $item['ownership_schema'] ?? '' )
		|| 'historical_legacy_disk' !== ( $item['ownership'] ?? '' )
		|| 1 !== absint( $item['evidence_version'] ?? 0 )
		|| ! empty( $item['remove_metadata'] )
		|| ! empty( $item['metadata_refs'] )
	) {
		return new WP_Error( 'yotm_historical_evidence_invalid', __( 'The historical item lacks exact disk-only evidence.', 'thumbnail-manager' ) );
	}
	$policy_hash = (string) ( $job_payload['legacy_policy']['hash'] ?? '' );
	if ( ! hash_equals( $policy_hash, (string) ( $item['legacy_policy_hash'] ?? '' ) ) ) {
		return new WP_Error( 'yotm_historical_policy_changed', __( 'The historical item policy changed.', 'thumbnail-manager' ) );
	}
	$proof = $item['historical_cohort_proof'] ?? null;
	$valid = yotm_validate_historical_cohort_proof( $proof, $policy_hash );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}
	$anchors_valid = yotm_revalidate_historical_cohort_anchors( $proof, $job_payload );
	if ( is_wp_error( $anchors_valid ) ) {
		return $anchors_valid;
	}
	if (
		! hash_equals( sanitize_key( $item['historical_size_key'] ?? '' ), sanitize_key( $proof['historical_size_key'] ?? '' ) )
		|| ! hash_equals( (string) ( $item['historical_signature'] ?? '' ), (string) ( $proof['signature'] ?? '' ) )
	) {
		return new WP_Error( 'yotm_historical_cohort_changed', __( 'The historical item no longer matches its sealed cohort.', 'thumbnail-manager' ) );
	}
	$observation                     = $item;
	$observation['source']           = 'historical_observation';
	$observation['ownership_schema'] = 'historical_observation_v1';
	$observation['ownership']        = 'historical_observation';
	$current                         = yotm_revalidate_historical_observation( $observation, $job_payload );
	return is_wp_error( $current ) ? $current : true;
}

/**
 * Validate immutable historical authority when an armed recovery path is absent.
 *
 * Live file facts cannot be replayed after the exact armed node was removed, so
 * this validator permits only journal closure and never metadata reconciliation.
 *
 * @param array $item Final immutable item with its armed journal.
 * @param array $job_payload Job payload.
 * @return true|WP_Error
 */
function yotm_prune_validate_historical_absent_recovery( $item, $job_payload ) {
	$policy = yotm_historical_policy_validate( $job_payload );
	if ( is_wp_error( $policy ) ) {
		return $policy;
	}
	if (
		'historical_legacy_generated_v1' !== ( $item['ownership_schema'] ?? '' )
		|| 'historical_legacy_disk' !== ( $item['ownership'] ?? '' )
		|| 1 !== absint( $item['evidence_version'] ?? 0 )
		|| ! empty( $item['remove_metadata'] )
		|| ! empty( $item['metadata_refs'] )
		|| empty( $item['prune_operation_journal_v1'] )
	) {
		return new WP_Error( 'yotm_historical_recovery_invalid', __( 'Historical recovery lacks exact disk-only journal evidence.', 'thumbnail-manager' ) );
	}
	$policy_hash = (string) ( $job_payload['legacy_policy']['hash'] ?? '' );
	if ( ! hash_equals( $policy_hash, (string) ( $item['legacy_policy_hash'] ?? '' ) ) ) {
		return new WP_Error( 'yotm_historical_policy_changed', __( 'The historical recovery policy changed.', 'thumbnail-manager' ) );
	}
	$proof = $item['historical_cohort_proof'] ?? null;
	$valid = yotm_validate_historical_cohort_proof( $proof, $policy_hash );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}
	$anchors_valid = yotm_revalidate_historical_cohort_anchors( $proof, $job_payload );
	if ( is_wp_error( $anchors_valid ) ) {
		return $anchors_valid;
	}
	if (
		! hash_equals( sanitize_key( $item['historical_size_key'] ?? '' ), sanitize_key( $proof['historical_size_key'] ?? '' ) )
		|| ! hash_equals( (string) ( $item['historical_signature'] ?? '' ), (string) ( $proof['signature'] ?? '' ) )
	) {
		return new WP_Error( 'yotm_historical_recovery_invalid', __( 'The historical recovery item does not match its sealed cohort.', 'thumbnail-manager' ) );
	}
	$sizes   = is_array( $job_payload['sizes'] ?? null ) ? $job_payload['sizes'] : array();
	$matches = yotm_legacy_matching_size_names(
		array_keys( $sizes ),
		$sizes,
		absint( $item['source_width'] ?? 0 ),
		absint( $item['source_height'] ?? 0 ),
		absint( $item['observed_width'] ?? 0 ),
		absint( $item['observed_height'] ?? 0 )
	);
	if ( ! empty( $matches ) ) {
		return new WP_Error( 'yotm_historical_current_projection', __( 'Current registered size evidence vetoes historical recovery.', 'thumbnail-manager' ) );
	}
	$live_matches = yotm_historical_live_projection_names(
		absint( $item['source_width'] ?? 0 ),
		absint( $item['source_height'] ?? 0 ),
		absint( $item['observed_width'] ?? 0 ),
		absint( $item['observed_height'] ?? 0 )
	);
	if ( ! empty( $live_matches ) ) {
		return new WP_Error( 'yotm_historical_current_projection', __( 'A live registered size projection vetoes historical recovery.', 'thumbnail-manager' ) );
	}
	return true;
}

/**
 * Return the initial disk-orphan reporting counters.
 *
 * @return array
 */
function yotm_initial_orphan_summary() {
	return array(
		'found'                      => array(),
		'delete'                     => array(),
		'kept_match'                 => array(),
		'total_files'                => 0,
		'skipped_original'           => 0,
		'skipped_original_sample'    => array(),
		'unmapped'                   => 0,
		'unmapped_sample'            => array(),
		'unmapped_skipped'           => 0,
		'unverified_sidecars'        => 0,
		'ambiguous_siblings'         => 0,
		'malformed_preserved'        => 0,
		'kept_dimension_preserved'   => 0,
		'verified_legacy'            => 0,
		'verified_legacy_current'    => 0,
		'verified_historical'        => 0,
		'historical_anchors'         => 0,
		'historical_observations'    => 0,
		'historical_below_threshold' => 0,
		'historical_shape_preserved' => 0,
		'historical_ambiguous'       => 0,
		'protected_sources'          => 0,
		'source_errors'              => 0,
	);
}

/**
 * Add or merge one normalized path into the candidate map.
 *
 * @param array  $candidates Candidate map, passed by reference.
 * @param string $path Candidate filesystem path.
 * @param array  $args Candidate metadata.
 */
function yotm_add_prune_candidate( &$candidates, $path, $args = array() ) {
	$path  = yotm_normalize_filesystem_path( $path );
	$proof = array(
		'attachment_id' => absint( $args['attachment_id'] ?? 0 ),
		'size'          => sanitize_key( $args['size'] ?? '' ),
		'filename'      => wp_basename( $path ),
		'mime'          => sanitize_mime_type( $args['mime'] ?? '' ),
		'selection'     => sanitize_key( $args['selection'] ?? '' ),
	);

	if ( isset( $candidates[ $path ] ) ) {
		if ( ! empty( $args['remove_metadata'] ) ) {
			$candidates[ $path ]['remove_metadata'] = 1;
		}

		if ( empty( $candidates[ $path ]['attachment_id'] ) && ! empty( $args['attachment_id'] ) ) {
			$candidates[ $path ]['attachment_id'] = absint( $args['attachment_id'] );
		}

		if ( empty( $candidates[ $path ]['size'] ) && ! empty( $args['size'] ) ) {
			$candidates[ $path ]['size'] = sanitize_key( $args['size'] );
		}

		if ( ! empty( $args['attachment_id'] ) && ! empty( $args['size'] ) && ! empty( $args['remove_metadata'] ) ) {
			$candidates[ $path ]['metadata_refs'][] = array(
				'attachment_id' => absint( $args['attachment_id'] ),
				'size'          => sanitize_key( $args['size'] ),
				'filename'      => wp_basename( $path ),
			);
		}
		$candidates[ $path ]['ownership_evidence'][] = $proof;

		return;
	}//end if

	$candidates[ $path ] = array(
		'path'               => $path,
		'attachment_id'      => isset( $args['attachment_id'] ) ? absint( $args['attachment_id'] ) : 0,
		'size'               => isset( $args['size'] ) ? sanitize_key( $args['size'] ) : '',
		'source'             => isset( $args['source'] ) ? sanitize_key( $args['source'] ) : 'metadata',
		'ownership_schema'   => 'generated_file_v1',
		'ownership'          => 'metadata_size',
		'ownership_evidence' => array( $proof ),
		'remove_metadata'    => ! empty( $args['remove_metadata'] ) ? 1 : 0,
		'metadata_refs'      => ! empty( $args['attachment_id'] ) && ! empty( $args['size'] ) && ! empty( $args['remove_metadata'] )
			? array(
				array(
					'attachment_id' => absint( $args['attachment_id'] ),
					'size'          => sanitize_key( $args['size'] ),
					'filename'      => wp_basename( $path ),
				),
			)
			: array(),
	);
}

/**
 * Collect metadata-backed prune candidates for a bounded attachment set.
 *
 * @param int[]           $ids Attachment IDs.
 * @param string|string[] $scan_bases Validated scan roots.
 * @param string[]        $keep Registered sizes to keep.
 * @param string[]        $to_remove Registered sizes to remove.
 * @param array           $sizes Registered size definitions.
 * @param bool            $discover_orphans Whether legacy metadata sizes are included.
 * @param array           $candidates Candidate map, passed by reference.
 * @param array           $orphan_summary Orphan counters, passed by reference.
 */
function yotm_collect_metadata_prune_candidates_for_ids( $ids, $scan_bases, $keep, $to_remove, $sizes, $discover_orphans, &$candidates, &$orphan_summary ) {
	$scan_bases = (array) $scan_bases;
	$keep_dims  = yotm_keep_dims_from_sizes( $keep, $sizes );
	$delete_map = array();
	$raw_batch  = yotm_media_reference_raw_postmeta_rows_batch( $ids, array( '_wp_attached_file', '_wp_attachment_metadata' ) );
	if ( is_wp_error( $raw_batch ) ) {
		$orphan_summary['source_errors'] = (int) ( $orphan_summary['source_errors'] ?? 0 ) + count( (array) $ids );
		return;
	}

	if ( isset( $orphan_summary['delete'] ) && is_array( $orphan_summary['delete'] ) ) {
		foreach ( $orphan_summary['delete'] as $dim ) {
			if ( is_string( $dim ) && '' !== $dim ) {
				$delete_map[ $dim ] = true;
			}
		}
	}

	foreach ( $ids as $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			continue;
		}
		$attached_rows = $raw_batch[ $attachment_id ]['_wp_attached_file'] ?? array();
		$metadata_rows = $raw_batch[ $attachment_id ]['_wp_attachment_metadata'] ?? array();
		if (
			1 !== count( $attached_rows )
			|| 1 !== count( $metadata_rows )
			|| ! is_string( $attached_rows[0]['value'] )
			|| '' === $attached_rows[0]['value']
			|| ! is_array( $metadata_rows[0]['value'] )
		) {
			$orphan_summary['source_errors'] = (int) ( $orphan_summary['source_errors'] ?? 0 ) + 1;
			continue;
		}
		$uploads  = wp_get_upload_dir();
		$base     = (string) ( $uploads['basedir'] ?? '' );
		$raw_file = $attached_rows[0]['value'];
		$file     = preg_match( '#^(?:[A-Za-z]:)?/#', $raw_file ) ? $raw_file : trailingslashit( $base ) . ltrim( $raw_file, '/\\' );
		$file     = yotm_media_source_canonical_path( $file );
		if ( is_wp_error( $file ) ) {
			$orphan_summary['source_errors'] = (int) ( $orphan_summary['source_errors'] ?? 0 ) + 1;
			continue;
		}

		$original_path = yotm_normalize_filesystem_path( $file );

		if ( ! yotm_is_path_inside_any_dir( $original_path, $scan_bases ) ) {
			continue;
		}

		$metadata = $metadata_rows[0]['value'];
		if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			continue;
		}

		$metadata_file = ! empty( $metadata['file'] ) && is_string( $metadata['file'] )
			? trailingslashit( $base ) . ltrim( $metadata['file'], '/\\' )
			: $original_path;
		$metadata_file = yotm_media_source_canonical_path( $metadata_file );
		if ( is_wp_error( $metadata_file ) ) {
			$orphan_summary['source_errors'] = (int) ( $orphan_summary['source_errors'] ?? 0 ) + 1;
			continue;
		}
		$upload_dir = trailingslashit( dirname( yotm_normalize_filesystem_path( $metadata_file ) ) );

		foreach ( $metadata['sizes'] as $size_name => $size_data ) {
			if ( ! is_array( $size_data ) ) {
				continue;
			}

			$filename = $size_data['file'] ?? ( $size_data['filename'] ?? '' );
			if ( '' === $filename ) {
				continue;
			}

			$thumb_path = yotm_normalize_filesystem_path( $upload_dir . wp_basename( $filename ) );

			if ( $thumb_path === $original_path || ! yotm_is_path_inside_any_dir( $thumb_path, $scan_bases ) ) {
				continue;
			}

			if ( in_array( $size_name, $keep, true ) ) {
				continue;
			}

			$registered_remove = in_array( $size_name, $to_remove, true );
			$orphan_remove     = false;
			$source            = 'metadata';

			if ( ! $registered_remove && $discover_orphans && ! isset( $sizes[ $size_name ] ) ) {
				$dim = yotm_dimension_from_size_data( $size_data );

				if ( '' !== $dim ) {
					$orphan_summary['found'][ $dim ] = ( $orphan_summary['found'][ $dim ] ?? 0 ) + 1;

					if ( yotm_dimension_matches_keep( $dim, $keep_dims ) ) {
						$orphan_summary['kept_match'][] = $dim;
					} else {
						$delete_map[ $dim ] = true;
						$orphan_remove      = true;
						$source             = 'metadata_orphan';
					}
				}
			}

			if ( ! $registered_remove && ! $orphan_remove ) {
				continue;
			}

			if ( ! is_file( $thumb_path ) ) {
				continue;
			}

			$protected = yotm_media_source_path_is_authoritative( $thumb_path );
			if ( is_wp_error( $protected ) ) {
				$orphan_summary['source_errors'] = (int) ( $orphan_summary['source_errors'] ?? 0 ) + 1;
				continue;
			}
			if ( $protected ) {
				$orphan_summary['protected_sources'] = (int) ( $orphan_summary['protected_sources'] ?? 0 ) + 1;
				continue;
			}

			yotm_add_prune_candidate(
				$candidates,
				$thumb_path,
				array(
					'attachment_id'   => $attachment_id,
					'size'            => (string) $size_name,
					'source'          => $source,
					'remove_metadata' => true,
					'mime'            => (string) ( $size_data['mime-type'] ?? '' ),
					'selection'       => $registered_remove ? 'registered_remove' : 'metadata_orphan',
				)
			);
		}//end foreach
	}//end foreach

	$orphan_summary['delete'] = array_keys( $delete_map );

	if ( isset( $orphan_summary['kept_match'] ) && is_array( $orphan_summary['kept_match'] ) ) {
		$orphan_summary['kept_match'] = array_values( array_unique( $orphan_summary['kept_match'] ) );
	}
}

/**
 * Convert metadata dimensions to a stable width-by-height key.
 *
 * @param array $size_data Attachment size metadata.
 * @return string
 */
function yotm_dimension_from_size_data( $size_data ) {
	$width  = isset( $size_data['width'] ) ? absint( $size_data['width'] ) : 0;
	$height = isset( $size_data['height'] ) ? absint( $size_data['height'] ) : 0;

	if ( $width <= 0 || $height <= 0 ) {
		return '';
	}

	return $width . 'x' . $height;
}

/**
 * Check whether a legacy dimension matches a retained registered size.
 *
 * @param string $dim Width-by-height key.
 * @param array  $keep_dims Dimension lookup maps.
 * @return bool
 */
function yotm_dimension_matches_keep( $dim, $keep_dims ) {
	if ( in_array( $dim, $keep_dims['exact'], true ) ) {
		return true;
	}

	if ( preg_match( '/^(\d+)x(\d+)$/', $dim, $matches ) ) {
		return in_array( (int) $matches[1], array_map( 'intval', $keep_dims['width_any'] ), true );
	}

	return false;
}

/**
 * Find the primary thumbnail and safe extension variants beside it.
 *
 * @param string $thumb_path Primary thumbnail path.
 * @return string[]
 */
function yotm_get_thumbnail_file_variants( $thumb_path ) {
	$thumb_path = yotm_normalize_filesystem_path( $thumb_path );

	return is_file( $thumb_path ) ? array( $thumb_path ) : array();
}

/**
 * Delete a file via the WordPress API and return bytes freed.
 *
 * @param string $path File path.
 * @return int
 */
function yotm_delete_file_and_count( $path ) {
	$uploads = wp_get_upload_dir();
	$base    = $uploads['basedir'] ?? '';

	if ( '' === $base || ! yotm_is_path_inside_dir( $path, $base ) ) {
		return 0;
	}

	$result = yotm_delete_file_with_result( $path );

	return ! empty( $result['deleted'] ) ? (int) $result['bytes'] : 0;
}

/**
 * Normalize an immutable prune path without resolving its final filesystem node.
 *
 * Journal identity and lstat inspection must address the reviewed path itself;
 * resolving a replacement symlink here would inspect its target instead.
 *
 * @param string $path Reviewed candidate path.
 * @return string
 */
function yotm_prune_journal_lexical_path( $path ) {
	return untrailingslashit( wp_normalize_path( (string) $path ) );
}

/**
 * Fingerprint one regular filesystem node from a single lstat snapshot.
 *
 * Device and inode bind the node across requests. Mode, link count, owner,
 * device type, and ctime make inode reuse or intervening stat changes fail
 * closed. Platforms that cannot expose a usable inode cannot authorize prune.
 *
 * @param array $stat lstat result.
 * @return string|WP_Error
 */
function yotm_prune_journal_node_fingerprint( $stat ) {
	$fields   = array( 'dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'rdev', 'ctime' );
	$identity = array();

	foreach ( $fields as $field ) {
		if ( ! array_key_exists( $field, $stat ) || ! is_int( $stat[ $field ] ) ) {
			return new WP_Error( 'yotm_prune_node_identity_unavailable', __( 'The reviewed prune file identity could not be established safely.', 'thumbnail-manager' ) );
		}
		$identity[] = $field . '=' . (string) $stat[ $field ];
	}
	if ( 0 >= $stat['ino'] ) {
		return new WP_Error( 'yotm_prune_node_identity_unavailable', __( 'The reviewed prune file identity could not be established safely.', 'thumbnail-manager' ) );
	}

	return hash( 'sha256', implode( '|', $identity ) );
}

/**
 * Inspect the exact filesystem node at an armed prune path without following symlinks.
 *
 * @param string $path Reviewed candidate path.
 * @return array{state:string,bytes:int,fingerprint?:string}|WP_Error
 */
function yotm_prune_journal_path_state( $path ) {
	$path = yotm_prune_journal_lexical_path( $path );
	clearstatcache( true, $path );
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A false lstat result is classified explicitly below and must not emit during recovery.
	$stat = @lstat( $path );

	if ( false === $stat ) {
		$parent = dirname( $path );
		if ( ! is_dir( $parent ) || ! is_readable( $parent ) || ! is_executable( $parent ) ) {
			return new WP_Error( 'yotm_prune_path_inspection_failed', __( 'The reviewed prune path could not be inspected safely.', 'thumbnail-manager' ) );
		}
		return array(
			'state' => 'absent',
			'bytes' => 0,
		);
	}

	$mode = absint( $stat['mode'] ?? 0 ) & 0170000;
	if ( 0100000 !== $mode || is_link( $path ) || ! is_file( $path ) ) {
		return array(
			'state' => 'changed',
			'bytes' => 0,
		);
	}
	$fingerprint = yotm_prune_journal_node_fingerprint( $stat );
	if ( is_wp_error( $fingerprint ) ) {
		return $fingerprint;
	}

	return array(
		'state'       => 'regular',
		'bytes'       => absint( $stat['size'] ?? 0 ),
		'fingerprint' => $fingerprint,
	);
}

/**
 * Prove that immutable candidate evidence covers every current generated owner.
 *
 * Source/protected owners always veto. Extra historical candidate evidence is
 * allowed so a repointed size does not prevent deleting its reviewed old file.
 *
 * @param array  $item Immutable candidate payload.
 * @param string $path Canonical candidate path.
 * @return true|WP_Error
 */
function yotm_prune_validate_live_reference_evidence( $item, $path ) {
	$owners = yotm_media_reference_path_owners( $path );
	if ( is_wp_error( $owners ) ) {
		return $owners;
	}
	if ( ! empty( $owners['protected'] ) ) {
		return new WP_Error( 'yotm_prune_path_protected', __( 'The file is now a protected attachment source or companion and was preserved.', 'thumbnail-manager' ) );
	}

	$represented = array();
	foreach ( (array) ( $item['metadata_refs'] ?? array() ) as $ref ) {
		if ( ! is_array( $ref ) ) {
			continue;
		}
		$key                 = absint( $ref['attachment_id'] ?? 0 ) . ':' . sanitize_key( $ref['size'] ?? '' ) . ':' . wp_basename( (string) ( $ref['filename'] ?? '' ) );
		$represented[ $key ] = true;
	}

	foreach ( $owners['generated'] as $owner ) {
		$key = absint( $owner['attachment_id'] ?? 0 ) . ':' . sanitize_key( $owner['size'] ?? '' ) . ':' . wp_basename( (string) ( $owner['filename'] ?? '' ) );
		if ( empty( $represented[ $key ] ) ) {
			return new WP_Error( 'yotm_prune_generated_owner_changed', __( 'A generated-file reference outside the reviewed manifest now owns this path.', 'thumbnail-manager' ) );
		}
	}

	return true;
}

/**
 * Validate immutable exact generated-file ownership before a side effect.
 *
 * @param array  $item Item payload.
 * @param string $uploads_base Uploads base path.
 * @return string|WP_Error Canonical candidate path.
 */
function yotm_validate_prune_item_ownership( $item, $uploads_base ) {
	if ( ! is_array( $item ) ) {
		return new WP_Error( 'yotm_prune_ownership_invalid', __( 'The prune item lacks exact generated-file ownership evidence.', 'thumbnail-manager' ) );
	}

	$path = yotm_media_source_canonical_path( $item['path'] ?? '' );
	if ( is_wp_error( $path ) || ! yotm_is_path_inside_dir( is_wp_error( $path ) ? '' : $path, $uploads_base ) ) {
		return is_wp_error( $path ) ? $path : new WP_Error( 'yotm_prune_path_invalid', __( 'File path is outside uploads.', 'thumbnail-manager' ) );
	}
	if ( 'legacy_generated_v1' === ( $item['ownership_schema'] ?? '' ) && 'legacy_disk' === ( $item['ownership'] ?? '' ) ) {
		if (
			1 !== absint( $item['evidence_version'] ?? 0 )
			|| empty( $item['attachment_id'] )
			|| ! empty( $item['remove_metadata'] )
			|| ! empty( $item['metadata_refs'] )
			|| 1 !== count( (array) ( $item['ownership_evidence'] ?? array() ) )
		) {
			return new WP_Error( 'yotm_legacy_evidence_invalid', __( 'The legacy prune item lacks exact disk-only evidence.', 'thumbnail-manager' ) );
		}

		return $path;
	}
	if ( 'historical_legacy_generated_v1' === ( $item['ownership_schema'] ?? '' ) && 'historical_legacy_disk' === ( $item['ownership'] ?? '' ) ) {
		if (
			1 !== absint( $item['evidence_version'] ?? 0 )
			|| empty( $item['attachment_id'] )
			|| ! empty( $item['remove_metadata'] )
			|| ! empty( $item['metadata_refs'] )
			|| 1 !== count( (array) ( $item['ownership_evidence'] ?? array() ) )
			|| ! is_array( $item['historical_cohort_proof'] ?? null )
		) {
			return new WP_Error( 'yotm_historical_evidence_invalid', __( 'The historical prune item lacks exact disk-only cohort evidence.', 'thumbnail-manager' ) );
		}

		return $path;
	}
	if ( 'generated_file_v1' !== ( $item['ownership_schema'] ?? '' ) || 'metadata_size' !== ( $item['ownership'] ?? '' ) ) {
		return new WP_Error( 'yotm_prune_ownership_invalid', __( 'The prune item lacks exact generated-file ownership evidence.', 'thumbnail-manager' ) );
	}

	$filename = wp_basename( $path );
	$evidence = is_array( $item['ownership_evidence'] ?? null ) ? $item['ownership_evidence'] : array();
	$refs     = is_array( $item['metadata_refs'] ?? null ) ? $item['metadata_refs'] : array();
	if ( empty( $evidence ) || empty( $refs ) ) {
		return new WP_Error( 'yotm_prune_ownership_invalid', __( 'The prune item lacks exact generated-file ownership evidence.', 'thumbnail-manager' ) );
	}

	$proof_keys = array();
	foreach ( $evidence as $proof ) {
		if (
			empty( $proof['attachment_id'] )
			|| '' === sanitize_key( $proof['size'] ?? '' )
			|| ! hash_equals( $filename, wp_basename( (string) ( $proof['filename'] ?? '' ) ) )
			|| ! in_array( $proof['selection'] ?? '', array( 'registered_remove', 'metadata_orphan' ), true )
		) {
			return new WP_Error( 'yotm_prune_ownership_invalid', __( 'The prune item contains malformed ownership evidence.', 'thumbnail-manager' ) );
		}
		$proof_keys[ absint( $proof['attachment_id'] ) . ':' . sanitize_key( $proof['size'] ) . ':' . $filename ] = true;
	}

	$ref_keys = array();
	foreach ( $refs as $ref ) {
		if ( empty( $ref['attachment_id'] ) || '' === sanitize_key( $ref['size'] ?? '' ) || ! hash_equals( $filename, wp_basename( (string) ( $ref['filename'] ?? '' ) ) ) ) {
			return new WP_Error( 'yotm_prune_ownership_invalid', __( 'The prune item contains a mismatched metadata reference.', 'thumbnail-manager' ) );
		}
		$ref_keys[ absint( $ref['attachment_id'] ) . ':' . sanitize_key( $ref['size'] ) . ':' . $filename ] = true;
	}

	ksort( $proof_keys );
	ksort( $ref_keys );
	if ( array_keys( $proof_keys ) !== array_keys( $ref_keys ) ) {
		return new WP_Error( 'yotm_prune_ownership_invalid', __( 'The prune item ownership evidence does not match its metadata references.', 'thumbnail-manager' ) );
	}

	return $path;
}

/**
 * Delete a manifest item and reconcile every attachment metadata reference.
 *
 * @param array|string $item Item payload.
 * @param string       $uploads_base Uploads base path.
 * @return array
 */
function yotm_delete_prune_item( $item, $uploads_base ) {
	$path = is_array( $item ) ? ( $item['path'] ?? '' ) : $item;
	$path = yotm_normalize_filesystem_path( $path );

	if ( '' === $path || ! yotm_is_path_inside_dir( $path, $uploads_base ) ) {
		return array(
			'deleted' => false,
			'skipped' => false,
			'bytes'   => 0,
			'error'   => __( 'File path is outside uploads.', 'thumbnail-manager' ),
		);
	}

	if ( ! is_file( $path ) ) {
		$reconciled = yotm_reconcile_prune_item_metadata( $item, $path );
		if ( is_wp_error( $reconciled ) ) {
			return $reconciled;
		}

		$legacy = is_array( $item ) && in_array( $item['ownership_schema'] ?? '', array( 'legacy_generated_v1', 'historical_legacy_generated_v1' ), true );
		return array(
			'deleted' => false,
			'skipped' => true,
			'bytes'   => 0,
			'message' => $legacy
				? __( 'The reviewed legacy file was already missing; no attachment metadata was changed.', 'thumbnail-manager' )
				: __( 'File was already missing; metadata was reconciled.', 'thumbnail-manager' ),
		);
	}

	$result = yotm_delete_file_with_result( $path );

	if ( ! empty( $result['deleted'] ) ) {
		$reconciled = yotm_reconcile_prune_item_metadata( $item, $path );
		if ( is_wp_error( $reconciled ) ) {
			return $reconciled;
		}
	}

	return $result;
}

/**
 * Remove all metadata references recorded for a deleted path.
 *
 * @param array|string $item Item payload.
 * @param string       $path File path.
 */
function yotm_reconcile_prune_item_metadata( $item, $path ) {
	if ( ! is_array( $item ) ) {
		return true;
	}
	if ( in_array( $item['ownership_schema'] ?? '', array( 'legacy_generated_v1', 'historical_legacy_generated_v1' ), true ) ) {
		$expected_ownership = 'historical_legacy_generated_v1' === ( $item['ownership_schema'] ?? '' ) ? 'historical_legacy_disk' : 'legacy_disk';
		if ( ( $item['ownership'] ?? '' ) !== $expected_ownership || ! empty( $item['remove_metadata'] ) || ! empty( $item['metadata_refs'] ) ) {
			return new WP_Error( 'yotm_legacy_metadata_authority_invalid', __( 'A disk-only legacy item attempted to acquire attachment metadata authority.', 'thumbnail-manager' ) );
		}

		return true;
	}

	$refs = is_array( $item['metadata_refs'] ?? null ) ? $item['metadata_refs'] : array();

	if ( empty( $refs ) && ! empty( $item['remove_metadata'] ) && ! empty( $item['attachment_id'] ) && ! empty( $item['size'] ) ) {
		$refs[] = array(
			'attachment_id' => $item['attachment_id'],
			'size'          => $item['size'],
			'filename'      => wp_basename( $path ),
		);
	}

	$seen = array();
	foreach ( $refs as $ref ) {
		$attachment_id = absint( $ref['attachment_id'] ?? 0 );
		$size          = sanitize_key( $ref['size'] ?? '' );
		$filename      = sanitize_file_name( $ref['filename'] ?? wp_basename( $path ) );
		$key           = $attachment_id . ':' . $size . ':' . $filename;

		if ( ! $attachment_id || '' === $size || isset( $seen[ $key ] ) ) {
			continue;
		}

		$seen[ $key ] = true;
		$removed      = yotm_remove_attachment_size_metadata( $attachment_id, $size, $filename );
		if ( is_wp_error( $removed ) ) {
			return $removed;
		}
	}

	return true;
}

/**
 * Delete a local file and report a useful failure.
 *
 * @param string $path File path.
 * @return array
 */
function yotm_delete_file_with_result( $path ) {
	$path = yotm_normalize_filesystem_path( $path );

	if ( ! is_file( $path ) ) {
		return array(
			'deleted' => false,
			'skipped' => true,
			'bytes'   => 0,
			'message' => __( 'File does not exist.', 'thumbnail-manager' ),
		);
	}

	if ( ! is_readable( $path ) ) {
		return array(
			'deleted' => false,
			'skipped' => false,
			'bytes'   => 0,
			'error'   => __( 'File is not readable.', 'thumbnail-manager' ),
		);
	}

	$bytes = filesize( $path );
	$bytes = false === $bytes ? 0 : $bytes;
	wp_delete_file( $path );

	if ( file_exists( $path ) ) {
		return array(
			'deleted' => false,
			'skipped' => false,
			'bytes'   => 0,
			'error'   => __( 'WordPress could not delete the file.', 'thumbnail-manager' ),
		);
	}

	return array(
		'deleted' => true,
		'skipped' => false,
		'bytes'   => $bytes,
		'error'   => '',
	);
}

/**
 * Remove a generated size reference from attachment metadata.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $size_name Size name.
 * @param string $filename Generated filename.
 * @return bool|WP_Error
 */
function yotm_remove_attachment_size_metadata( $attachment_id, $size_name, $filename ) {
	$rows = yotm_media_reference_raw_postmeta_rows( $attachment_id, '_wp_attachment_metadata' );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}
	if ( 1 !== count( $rows ) || ! is_array( $rows[0]['value'] ) ) {
		return new WP_Error( 'yotm_prune_metadata_state_ambiguous', __( 'The file was removed, but raw attachment metadata could not be reconciled safely.', 'thumbnail-manager' ) );
	}
	$metadata = $rows[0]['value'];

	if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
		return false;
	}

	if ( ! isset( $metadata['sizes'][ $size_name ] ) || ! is_array( $metadata['sizes'][ $size_name ] ) ) {
		return true;
	}

	$size_file = $metadata['sizes'][ $size_name ]['file'] ?? ( $metadata['sizes'][ $size_name ]['filename'] ?? '' );
	if ( '' === $size_file || ! hash_equals( wp_basename( (string) $size_file ), wp_basename( (string) $filename ) ) ) {
		return true;
	}

	unset( $metadata['sizes'][ $size_name ] );
	unset( $GLOBALS['yotm_media_source_last_error'] );
	$updated = wp_update_attachment_metadata( $attachment_id, $metadata );
	if ( false === $updated ) {
		if ( isset( $GLOBALS['yotm_media_source_last_error'] ) && is_wp_error( $GLOBALS['yotm_media_source_last_error'] ) ) {
			return $GLOBALS['yotm_media_source_last_error'];
		}
		return new WP_Error( 'yotm_prune_metadata_update_failed', __( 'The file was removed, but its exact metadata reference could not be reconciled.', 'thumbnail-manager' ) );
	}

	return true;
}
