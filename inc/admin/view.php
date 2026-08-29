<?php
/**
 * Thumbnail Manager administration page renderer.
 *
 * @package Thumbnail_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the already prepared administration page view model.
 *
 * @param array $view Page presentation data.
 */
function yotm_render_admin_view( $view ) {
	$base                      = $view['base'];
	$sizes                     = $view['sizes'];
	$subpaths                  = $view['subpaths'];
	$prune_subpath_groups      = $view['prune_subpath_groups'];
	$disabled_now              = $view['disabled_now'];
	$default_keep              = $view['default_keep'];
	$sizes_saved_notice        = $view['sizes_saved_notice'];
	$run_regenerate_after_save = $view['run_regenerate_after_save'];
	?>
	<div class="wrap yotm-wrap">
		<h1><?php echo esc_html__( 'Thumbnail Management', 'thumbnail-manager' ); ?></h1>
		<p><span class="dashicons dashicons-open-folder"></span> <code><?php echo esc_html( $base ); ?></code></p>

		<?php if ( $sizes_saved_notice ) : ?>
			<div class="notice notice-success">
				<p><?php echo esc_html( $sizes_saved_notice ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $run_regenerate_after_save ) ) : ?>
			<script>
				window.YOTM_RUN_REGENERATE_AFTER_SAVE = true;
			</script>
		<?php endif; ?>

		<section class="yo-job-center" aria-labelledby="yotm_job_center_title">
			<h2 id="yotm_job_center_title" class="screen-reader-text"><?php echo esc_html__( 'Job activity', 'thumbnail-manager' ); ?></h2>
			<div id="yotm_active_job" class="yo-active-job yo-hidden" role="status" aria-live="polite">
				<div>
					<strong id="yotm_active_job_title"><?php echo esc_html__( 'Active job', 'thumbnail-manager' ); ?></strong>
					<span id="yotm_active_job_meta"></span>
				</div>
				<button type="button" id="yotm_active_job_view" class="button"><?php echo esc_html__( 'View job', 'thumbnail-manager' ); ?></button>
			</div>
			<details id="yotm_recent_jobs" class="yo-recent-jobs">
				<summary><?php echo esc_html__( 'Recent jobs', 'thumbnail-manager' ); ?></summary>
				<div class="yo-table-scroll">
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php echo esc_html__( 'Type', 'thumbnail-manager' ); ?></th>
								<th><?php echo esc_html__( 'Status', 'thumbnail-manager' ); ?></th>
								<th><?php echo esc_html__( 'Progress', 'thumbnail-manager' ); ?></th>
								<th><?php echo esc_html__( 'Updated', 'thumbnail-manager' ); ?></th>
							</tr>
						</thead>
						<tbody id="yotm_recent_jobs_body"></tbody>
					</table>
				</div>
			</details>
		</section>

			<div class="yo-tabs" id="yotm_tabs" role="tablist">
				<button type="button" class="yo-tab active" data-tab="regenerate" id="yotm_tab_regenerate" role="tab" tabindex="0" aria-controls="yotm_panel_regenerate" aria-selected="true">
					<?php echo esc_html__( 'Regenerate', 'thumbnail-manager' ); ?>
				</button>
				<button type="button" class="yo-tab" data-tab="recommendations" id="yotm_tab_recommendations" role="tab" tabindex="-1" aria-controls="yotm_panel_recommendations" aria-selected="false">
					<?php echo esc_html__( 'Recommendations', 'thumbnail-manager' ); ?>
				</button>
				<button type="button" class="yo-tab" data-tab="prune" id="yotm_tab_prune" role="tab" tabindex="-1" aria-controls="yotm_panel_prune" aria-selected="false">
					<?php echo esc_html__( 'Prune Files', 'thumbnail-manager' ); ?>
				</button>
				<button type="button" class="yo-tab" data-tab="sizes" id="yotm_tab_sizes" role="tab" tabindex="-1" aria-controls="yotm_panel_sizes" aria-selected="false">
					<?php echo esc_html__( 'Thumbnail Sizes', 'thumbnail-manager' ); ?>
				</button>
			</div>

		<!-- TAB 1: REGENERATE -->
			<div class="yo-panel active" id="yotm_panel_regenerate" role="tabpanel" aria-labelledby="yotm_tab_regenerate">
			<p>
				<?php
				echo wp_kses(
					__(
						'Regenerate thumbnails for existing image attachments in your Media Library. This uses the sizes that are currently <strong>enabled</strong> in the <em>Thumbnail Sizes</em> tab.',
						'thumbnail-manager'
					),
					array(
						'strong' => array(),
						'em'     => array(),
					)
				);
				?>
			</p>

			<p class="description">
				<?php echo esc_html__( 'Recommended after changing thumbnail size settings or after enabling new sizes.', 'thumbnail-manager' ); ?>
			</p>

			<fieldset class="yo-row yo-scope-picker" id="yotm_regen_scope_picker">
				<legend><strong><?php echo esc_html__( 'Scope', 'thumbnail-manager' ); ?></strong></legend>
				<label class="yo-scope-mode">
					<input type="radio" name="yotm_regen_scope" value="all" checked>
					<span><strong><?php echo esc_html__( 'All media', 'thumbnail-manager' ); ?></strong></span>
				</label>
				<label class="yo-scope-mode">
					<input type="radio" name="yotm_regen_scope" value="year">
					<span><strong><?php echo esc_html__( 'Current year only', 'thumbnail-manager' ); ?></strong></span>
				</label>
				<label class="yo-scope-mode">
					<input type="radio" name="yotm_regen_scope" value="subpath">
					<span><strong><?php echo esc_html__( 'Specific uploads folder', 'thumbnail-manager' ); ?></strong></span>
				</label>
				<label class="yo-scope-mode">
					<input type="radio" name="yotm_regen_scope" value="ids">
					<span><strong><?php echo esc_html__( 'Specific attachment IDs', 'thumbnail-manager' ); ?></strong></span>
				</label>
			</fieldset>

			<div class="yo-row yo-hidden" id="yotm_regen_subpath_wrap">
				<label for="yotm_regen_subpath">
					<?php
					echo wp_kses(
						__( 'Choose subfolder inside <code>uploads/</code>:', 'thumbnail-manager' ),
						array( 'code' => array() )
					);
					?>
				</label><br>
				<select id="yotm_regen_subpath">
					<?php foreach ( $subpaths as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>">
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="yo-row yo-hidden" id="yotm_regen_ids_wrap">
				<label for="yotm_regen_attachment_ids">
					<?php echo esc_html__( 'Attachment IDs', 'thumbnail-manager' ); ?>
				</label><br>
				<textarea
					id="yotm_regen_attachment_ids"
					rows="4"
					style="width:100%;max-width:720px;"
					placeholder="<?php echo esc_attr__( 'Example: 123, 456, 789', 'thumbnail-manager' ); ?>"
				></textarea>
				<p class="description">
					<?php echo esc_html__( 'Enter one or more Media Library attachment IDs separated by commas or spaces.', 'thumbnail-manager' ); ?>
				</p>
			</div>

			<fieldset class="yo-row yo-radio-group" id="yotm_regen_mode_group">
				<legend><strong><?php echo esc_html__( 'Regeneration mode', 'thumbnail-manager' ); ?></strong></legend>
				<label class="yo-radio-option">
					<input type="radio" name="yotm_regen_mode" value="missing" checked>
					<span><strong><?php echo esc_html__( 'Generate missing sizes only', 'thumbnail-manager' ); ?></strong><small><?php echo esc_html__( 'Faster and recommended for routine maintenance.', 'thumbnail-manager' ); ?></small></span>
				</label>
				<label class="yo-radio-option">
					<input type="radio" name="yotm_regen_mode" value="force">
					<span><strong><?php echo esc_html__( 'Force regenerate all selected images', 'thumbnail-manager' ); ?></strong><small><?php echo esc_html__( 'Rebuilds metadata from the original image and removes obsolete generated files.', 'thumbnail-manager' ); ?></small></span>
				</label>
			</fieldset>

			<div id="yotm_regen_force_note" class="notice notice-warning inline yo-hidden" role="alert">
				<p><?php echo esc_html__( 'Force mode is slower and may replace existing thumbnail files. Originals remain protected.', 'thumbnail-manager' ); ?></p>
			</div>

			<p class="yo-row">
				<button type="button" id="yotm_regen_run" class="button button-primary">
					<?php echo esc_html__( 'Run regenerate', 'thumbnail-manager' ); ?>
				</button>
				<button id="yotm_regen_cancel" type="button" class="button yo-hidden">
					<?php echo esc_html__( 'Stop job', 'thumbnail-manager' ); ?>
				</button>
			</p>

				<div id="yotm_regen_progress" class="yo-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
					<div class="bar"></div>
				</div>
				<div id="yotm_regen_status" class="yo-status" aria-live="polite"></div>
			<div id="yotm_regen_results" tabindex="-1" aria-live="polite"></div>
		</div>

		<!-- TAB: RECOMMENDATIONS -->
			<div class="yo-panel" id="yotm_panel_recommendations" role="tabpanel" aria-labelledby="yotm_tab_recommendations" hidden>

			<h2>
				<?php echo esc_html__( 'Recommendations', 'thumbnail-manager' ); ?>
			</h2>

			<p>
				<?php echo esc_html__( 'These suggestions use policy protections and positive content-reference heuristics to recommend keeping sizes. Missing evidence never proves a size is unused, and recommendations never authorize deletion.', 'thumbnail-manager' ); ?>
			</p>

				<div class="yo-row">
					<button type="button" id="yotm_recommend_scan" class="button button-primary">
						<?php echo esc_html__( 'Scan media usage', 'thumbnail-manager' ); ?>
					</button>
					<button id="yotm_recommend_cancel" type="button" class="button yo-hidden">
						<?php echo esc_html__( 'Stop job', 'thumbnail-manager' ); ?>
					</button>
				</div>

				<div id="yotm_recommend_progress" class="yo-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
					<div class="bar"></div>
				</div>

				<div id="yotm_recommend_status" class="yo-status" aria-live="polite"></div>

			<div class="yo-card-grid" id="yotm_recommend_summary">

				<div class="yo-card">
						<strong><?php echo esc_html__( 'Protected', 'thumbnail-manager' ); ?></strong>
						<div class="yo-card-value" id="yotm_recommend_protected_count">—</div>
				</div>

				<div class="yo-card">
						<strong><?php echo esc_html__( 'Detected References', 'thumbnail-manager' ); ?></strong>
						<div class="yo-card-value" id="yotm_recommend_reference_count">—</div>
				</div>

				<div class="yo-card">
						<strong><?php echo esc_html__( 'Unknown / Review', 'thumbnail-manager' ); ?></strong>
						<div class="yo-card-value" id="yotm_recommend_unknown_count">—</div>
				</div>

				<div class="yo-card">
						<strong><?php echo esc_html__( 'Generated Footprint', 'thumbnail-manager' ); ?></strong>
						<div class="yo-card-value" id="yotm_recommend_generated_bytes">—</div>
				</div>

			</div>

			<div id="yotm_recommend_results" tabindex="-1" aria-live="polite"></div>

			<div class="yo-row">

				<button type="button" id="yotm_apply_recommendations" class="button button-primary yo-hidden">
						<?php echo esc_html__( 'Apply safe recommendations', 'thumbnail-manager' ); ?>
				</button>

				<button type="button" id="yotm_recommend_go_prune" class="button yo-hidden">
					<?php echo esc_html__( 'Review in Prune Files', 'thumbnail-manager' ); ?>
				</button>

			</div>

				<p class="description">
					<?php echo esc_html__( 'Apply only enables sizes supported by keep evidence. Unknown sizes keep their current setting; saving remains a separate action.', 'thumbnail-manager' ); ?>
				</p>

		</div>

		<!-- TAB 2: PRUNE FILES -->
			<div class="yo-panel" id="yotm_panel_prune" role="tabpanel" aria-labelledby="yotm_tab_prune" hidden>
			<p>
				<?php
				echo wp_kses(
					__(
						'Choose which registered image sizes to <strong>KEEP</strong>, then scan. The scan only builds a reviewable manifest; deletion requires a separate explicit approval after the scan completes.',
						'thumbnail-manager'
					),
					array(
						'strong' => array(),
						'em'     => array(),
					)
				);
				?>
				</p>

			<ol id="yotm_prune_steps" class="yo-stepper" aria-label="<?php echo esc_attr__( 'Prune progress', 'thumbnail-manager' ); ?>">
				<li data-step="configure" class="is-active" aria-current="step"><span>1</span><?php echo esc_html__( 'Configure', 'thumbnail-manager' ); ?></li>
				<li data-step="scanning"><span>2</span><?php echo esc_html__( 'Scan', 'thumbnail-manager' ); ?></li>
				<li data-step="review"><span>3</span><?php echo esc_html__( 'Review', 'thumbnail-manager' ); ?></li>
				<li data-step="deleting"><span>4</span><?php echo esc_html__( 'Delete', 'thumbnail-manager' ); ?></li>
				<li data-step="completed"><span>5</span><?php echo esc_html__( 'Complete', 'thumbnail-manager' ); ?></li>
			</ol>

			<fieldset class="yo-row yo-scope-picker" id="yotm_prune_scope_picker">
				<legend><strong><?php echo esc_html__( 'Uploads scope', 'thumbnail-manager' ); ?></strong></legend>
				<label class="yo-scope-mode">
					<input type="radio" name="yotm_prune_scope" value="all" checked>
					<span><strong><?php echo esc_html__( 'All uploads', 'thumbnail-manager' ); ?></strong><small><?php echo esc_html__( 'Scan the complete uploads directory.', 'thumbnail-manager' ); ?></small></span>
				</label>
				<label class="yo-scope-mode">
					<input type="radio" name="yotm_prune_scope" value="selected">
					<span><strong><?php echo esc_html__( 'Selected folders', 'thumbnail-manager' ); ?></strong><small><?php echo esc_html__( 'Choose multiple years or months to reduce scan time.', 'thumbnail-manager' ); ?></small></span>
				</label>

				<div id="yotm_subpath_picker" class="yo-subpath-picker yo-hidden">
					<div class="yo-subpath-toolbar">
						<label for="yotm_subpath_search" class="screen-reader-text"><?php echo esc_html__( 'Search uploads folders', 'thumbnail-manager' ); ?></label>
						<input type="search" id="yotm_subpath_search" placeholder="<?php echo esc_attr__( 'Search year or folder, e.g. 2024/08…', 'thumbnail-manager' ); ?>" autocomplete="off">
						<div class="yo-subpath-actions">
							<button type="button" class="button button-small" id="yotm_subpath_select_visible"><?php echo esc_html__( 'Select visible', 'thumbnail-manager' ); ?></button>
							<button type="button" class="button button-small" id="yotm_subpath_clear"><?php echo esc_html__( 'Clear', 'thumbnail-manager' ); ?></button>
						</div>
					</div>

					<div id="yotm_subpath_options" class="yo-subpath-options" role="group" aria-label="<?php echo esc_attr__( 'Available uploads folders', 'thumbnail-manager' ); ?>">
						<?php
						foreach ( $prune_subpath_groups as $group_name => $group_data ) :
							$child_items  = array_filter(
								$group_data['items'],
								static function ( $item_path ) use ( $group_name ) {
									return $item_path !== $group_name;
								},
								ARRAY_FILTER_USE_KEY
							);
							$folder_count = max( 1, count( $child_items ) );
							?>
							<details class="yo-subpath-group" data-search="<?php echo esc_attr( strtolower( $group_name . ' ' . implode( ' ', array_keys( $group_data['items'] ) ) ) ); ?>">
								<summary>
									<span><?php echo esc_html( $group_name ); ?></span>
									<small>
										<?php
										printf(
											/* translators: %s: localized number of month/subfolder options. */
											esc_html( _n( '%s folder', '%s folders', $folder_count, 'thumbnail-manager' ) ),
											esc_html( number_format_i18n( $folder_count ) )
										);
										?>
									</small>
								</summary>
								<div class="yo-subpath-group-items">
									<label class="yo-subpath-option yo-subpath-parent" data-search="<?php echo esc_attr( strtolower( $group_name ) ); ?>">
										<input type="checkbox" class="yotm_subpath_option" value="<?php echo esc_attr( $group_name ); ?>" data-kind="parent">
										<span><strong><?php echo esc_html( 'uploads/' . $group_name . '/' ); ?></strong><small><?php echo esc_html__( 'Entire folder', 'thumbnail-manager' ); ?></small></span>
									</label>

									<?php foreach ( $child_items as $item_path => $item_label ) : ?>
										<label class="yo-subpath-option" data-search="<?php echo esc_attr( strtolower( $item_path . ' ' . $item_label ) ); ?>">
											<input type="checkbox" class="yotm_subpath_option" value="<?php echo esc_attr( $item_path ); ?>" data-ancestor="<?php echo esc_attr( $group_name ); ?>">
											<span><?php echo esc_html( 'uploads/' . $item_path . '/' ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
							</details>
						<?php endforeach; ?>
						<p id="yotm_subpath_no_results" class="description yo-hidden"><?php echo esc_html__( 'No folders match this search.', 'thumbnail-manager' ); ?></p>
					</div>

					<div class="yo-subpath-selection" aria-live="polite">
						<strong id="yotm_subpath_selection_count"><?php echo esc_html__( 'No folders selected', 'thumbnail-manager' ); ?></strong>
						<div id="yotm_subpath_chips" class="yo-subpath-chips"></div>
					</div>
				</div>
			</fieldset>

				<form id="yotm_form" onsubmit="return false;">
				<div class="yo-table-scroll">
					<table class="widefat striped yo-sizes" style="max-width:980px;">
					<thead>
						<tr>
							<th style="width:4ch;"><?php echo esc_html__( 'Keep', 'thumbnail-manager' ); ?></th>
							<th><?php echo esc_html__( 'Size name', 'thumbnail-manager' ); ?></th>
							<th style="width:120px;"><?php echo esc_html__( 'Target (WxH)', 'thumbnail-manager' ); ?></th>
							<th style="width:4ch;"><?php echo esc_html__( 'Crop', 'thumbnail-manager' ); ?></th>
								<th><?php echo esc_html__( 'Deletion evidence', 'thumbnail-manager' ); ?></th>
							<th style="width:160px;"><?php echo esc_html__( 'Status', 'thumbnail-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					foreach ( $sizes as $name => $def ) :
						$w = (int) ( $def['width'] ?? 0 );
						$h = (int) ( $def['height'] ?? 0 );
						if ( preg_match( '/^(\d+)x(\d+)$/', $name, $m ) ) {
							$w = 0 === $w ? (int) $m[1] : $w;
							$h = 0 === $h ? (int) $m[2] : $h;
						}
						$crop = ! empty( $def['crop'] );

							$note = __( 'Deletes only the exact generated filename recorded in attachment metadata; unverified sidecars and siblings are report-only.', 'thumbnail-manager' );

						$is_disabled = in_array( $name, $disabled_now, true );
						?>
						<tr>
							<td>
								<input
									type="checkbox"
									class="yotm_keep"
									value="<?php echo esc_attr( $name ); ?>"
									<?php checked( in_array( $name, $default_keep, true ) ); ?>
								>
							</td>
							<td><code><?php echo esc_html( $name ); ?></code></td>
							<td><?php echo esc_html( "{$w} × {$h}" ); ?></td>
							<td><?php echo $crop ? esc_html__( 'Yes', 'thumbnail-manager' ) : esc_html__( 'No', 'thumbnail-manager' ); ?></td>
							<td><?php echo esc_html( $note ); ?></td>
							<td>
								<?php
								if ( $is_disabled ) {
									echo '<span style="color:#b32d2e">' . esc_html__( 'Disabled for new uploads', 'thumbnail-manager' ) . '</span>';
								} else {
									echo '<span style="color:#1a7f37">' . esc_html__( 'Enabled', 'thumbnail-manager' ) . '</span>';
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
						</tbody>
					</table>
				</div>

					<p class="yo-row">
						<label>
							<input type="checkbox" id="yotm_discover_orphans" value="1">
							<?php
							echo wp_kses(
								__(
									'Include verified legacy thumbnails (disk-only)',
									'thumbnail-manager'
								),
								array(
									'strong' => array(),
								)
							);
							?>
					</label>
					<span class="description" style="display:block;margin-top:4px;">
						<?php echo esc_html__( 'Only files bound to one authoritative local attachment source, an explicitly removed registered size, matching decoded dimensions/MIME, and no current reference can enter the review manifest. Ambiguous files and sidecars are preserved. Choose a smaller folder first on very large sites.', 'thumbnail-manager' ); ?>
					</span>
				</p>

						<p class="yo-row yo-actions">
							<button type="button" id="yotm_run" class="button button-primary">
								<?php echo esc_html__( 'Scan and review', 'thumbnail-manager' ); ?>
							</button>
							<button id="yotm_cancel" type="button" class="button yo-hidden">
							<?php echo esc_html__( 'Stop job', 'thumbnail-manager' ); ?>
						</button>
					</p>
			</form>

				<div id="yotm_progress" class="yo-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="bar"></div></div>
				<div id="yotm_status" class="yo-status" aria-live="polite"></div>
				<div id="yotm_results" tabindex="-1" aria-live="polite"></div>

			<section id="yotm_review_panel" class="yo-review-panel yo-hidden" tabindex="-1" aria-labelledby="yotm_review_title">
				<div class="yo-review-heading">
					<div>
						<h2 id="yotm_review_title"><?php echo esc_html__( 'Review deletion manifest', 'thumbnail-manager' ); ?></h2>
						<p><?php echo esc_html__( 'This manifest is immutable. Nothing will be deleted until you explicitly approve it below.', 'thumbnail-manager' ); ?></p>
					</div>
					<span class="yo-badge yo-review-badge"><?php echo esc_html__( 'Awaiting approval', 'thumbnail-manager' ); ?></span>
				</div>

				<div class="yo-review-summary">
					<div><span><?php echo esc_html__( 'Files', 'thumbnail-manager' ); ?></span><strong id="yotm_review_count">0</strong></div>
					<div><span><?php echo esc_html__( 'Estimated size', 'thumbnail-manager' ); ?></span><strong id="yotm_review_size">0 B</strong></div>
					<div><span><?php echo esc_html__( 'Scope', 'thumbnail-manager' ); ?></span><strong id="yotm_review_scope">uploads/</strong></div>
					<div><span><?php echo esc_html__( 'Review expires', 'thumbnail-manager' ); ?></span><strong id="yotm_review_expiry">—</strong></div>
				</div>

				<p class="yo-manifest-hash"><strong><?php echo esc_html__( 'Manifest hash', 'thumbnail-manager' ); ?></strong> <code id="yotm_review_hash"></code></p>
				<div id="yotm_review_orphans"></div>

				<div class="yo-manifest-tools">
					<label for="yotm_manifest_search" class="screen-reader-text"><?php echo esc_html__( 'Filter manifest', 'thumbnail-manager' ); ?></label>
					<input type="search" id="yotm_manifest_search" placeholder="<?php echo esc_attr__( 'Filter by path or size…', 'thumbnail-manager' ); ?>">
					<span id="yotm_manifest_count" aria-live="polite"></span>
				</div>

				<div class="yo-table-scroll">
					<table class="widefat striped yo-manifest-table">
						<thead>
							<tr>
								<th><?php echo esc_html__( 'File path', 'thumbnail-manager' ); ?></th>
								<th><?php echo esc_html__( 'Attachment', 'thumbnail-manager' ); ?></th>
								<th><?php echo esc_html__( 'Ownership evidence', 'thumbnail-manager' ); ?></th>
								<th><?php echo esc_html__( 'Estimated size', 'thumbnail-manager' ); ?></th>
							</tr>
						</thead>
						<tbody id="yotm_manifest_body"></tbody>
					</table>
				</div>

				<div class="yo-pagination">
					<button type="button" id="yotm_manifest_prev" class="button"><?php echo esc_html__( 'Previous', 'thumbnail-manager' ); ?></button>
					<span id="yotm_manifest_page"><?php echo esc_html__( 'Page 1 of 1', 'thumbnail-manager' ); ?></span>
					<button type="button" id="yotm_manifest_next" class="button"><?php echo esc_html__( 'Next', 'thumbnail-manager' ); ?></button>
				</div>

				<div class="yo-delete-approval">
					<label>
						<input type="checkbox" id="yotm_review_confirm">
						<span><?php echo esc_html__( 'I reviewed this manifest and understand that only these files will be permanently deleted.', 'thumbnail-manager' ); ?></span>
					</label>
					<p class="description"><?php echo esc_html__( 'Approval is bound to your user, this site, and the manifest hash. It expires 30 minutes after confirmation.', 'thumbnail-manager' ); ?></p>
					<button type="button" id="yotm_approve_delete" class="button yo-button-danger" disabled><?php echo esc_html__( 'Delete reviewed files', 'thumbnail-manager' ); ?></button>
				</div>
			</section>
			</div>

		<!-- TAB 3: THUMBNAIL SIZES -->
			<div class="yo-panel" id="yotm_panel_sizes" role="tabpanel" aria-labelledby="yotm_tab_sizes" hidden>
			<p>
				<?php
				echo wp_kses(
					__(
						'Choose which sizes to <strong>generate for future uploads</strong>. Sizes you uncheck will be disabled at upload time.  To remove files that already exist for disabled sizes, use the <em>Prune Files</em> tab.',
						'thumbnail-manager'
					),
					array(
						'strong' => array(),
						'em'     => array(),
					)
				);
				?>
			</p>

				<form method="post">
					<?php wp_nonce_field( 'yotm_sizes_save_nonce', 'yotm_sizes_save_nonce' ); ?>
				<div class="yo-table-scroll">
					<table class="widefat striped" style="max-width:980px;">
					<thead>
						<tr>
							<th style="width:80px;"><?php echo esc_html__( 'Generate', 'thumbnail-manager' ); ?></th>
							<th><?php echo esc_html__( 'Size name', 'thumbnail-manager' ); ?></th>
							<th style="width:160px;"><?php echo esc_html__( 'Target (WxH)', 'thumbnail-manager' ); ?></th>
							<th style="width:100px;"><?php echo esc_html__( 'Crop', 'thumbnail-manager' ); ?></th>
							<th><?php echo esc_html__( 'Notes', 'thumbnail-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					foreach ( $sizes as $name => $def ) :
						$w = (int) ( $def['width'] ?? 0 );
						$h = (int) ( $def['height'] ?? 0 );
						if ( preg_match( '/^(\d+)x(\d+)$/', $name, $m ) ) {
							$w = 0 === $w ? (int) $m[1] : $w;
							$h = 0 === $h ? (int) $m[2] : $h;
						}
						$crop    = ! empty( $def['crop'] );
						$enabled = ! in_array( $name, $disabled_now, true );
						$core    = in_array( $name, array( 'thumbnail', 'medium', 'large', 'medium_large', '1536x1536', '2048x2048' ), true );
						?>
						<tr>
							<td>
								<input
									type="checkbox"
									name="yotm_enable_sizes[]"
									value="<?php echo esc_attr( $name ); ?>"
									<?php checked( $enabled ); ?>
								>
							</td>
							<td>
								<code><?php echo esc_html( $name ); ?></code>
								<?php
								if ( $core ) {
									echo '<span class="dashicons dashicons-wordpress-alt" title="' . esc_attr__( 'Core', 'thumbnail-manager' ) . '"></span>';
								}
								?>
							</td>
							<td><?php echo esc_html( "{$w} × {$h}" ); ?></td>
							<td><?php echo $crop ? esc_html__( 'Yes', 'thumbnail-manager' ) : esc_html__( 'No', 'thumbnail-manager' ); ?></td>
							<td>
								<?php
								echo $enabled
									? esc_html__( 'Enabled', 'thumbnail-manager' )
									: esc_html__( 'Disabled (won’t be generated)', 'thumbnail-manager' );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<p class="yo-row">
					<button class="button button-primary" name="yotm_sizes_save" value="1">
						<?php echo esc_html__( 'Save changes', 'thumbnail-manager' ); ?>
					</button>
					<button type="submit" class="button" name="yotm_save_and_regenerate" value="1">
						<?php echo esc_html__( 'Save changes and run regenerate', 'thumbnail-manager' ); ?>
					</button>
					<button type="button" class="button" id="yotm_sizes_enable_core">
						<?php echo esc_html__( 'Enable core only', 'thumbnail-manager' ); ?>
					</button>
					<button type="button" class="button" id="yotm_sizes_enable_all">
						<?php echo esc_html__( 'Enable all', 'thumbnail-manager' ); ?>
					</button>
					<button type="button" class="button" id="yotm_sizes_disable_all">
						<?php echo esc_html__( 'Disable all', 'thumbnail-manager' ); ?>
					</button>
				</p>

				<p class="description">
					<?php
					echo wp_kses(
						__(
							'Changes above affect <strong>future uploads</strong>. Use the button below to remove existing thumbnails for disabled sizes.',
							'thumbnail-manager'
						),
						array(
							'strong' => array(),
						)
					);
					?>
				</p>

				<div class="yo-row" style="border-top:1px solid #ddd;padding-top:12px;margin-top:16px;">
					<strong><?php echo esc_html__( 'Danger zone', 'thumbnail-manager' ); ?></strong>
					<p>
						<?php
						echo wp_kses(
							__(
								'This starts a review-first Prune scan for metadata-backed and verified legacy thumbnails matching sizes that are <em>disabled</em> below. Nothing is deleted until you review and explicitly approve the immutable manifest. Originals remain protected.',
								'thumbnail-manager'
							),
							array(
								'em' => array(),
							)
						);
						?>
					</p>
					<button type="button" class="button button-secondary" id="yotm_sizes_prune_disabled">
						<?php echo esc_html__( 'Delete thumbnails of disabled sizes', 'thumbnail-manager' ); ?>
					</button>
				</div>
			</form>
		</div>
	</div>
	<?php
}
