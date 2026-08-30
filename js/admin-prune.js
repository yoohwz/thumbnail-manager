(function($, core){
  'use strict';

  if (!core) {
    return;
  }

  const activateTab = core.activateTab;
  const cancelJob = core.cancelJob;
  const errorDetails = core.errorDetails;
  const escapeHtml = core.escapeHtml;
  const forgetJob = core.forgetJob;
  const formatBytes = core.formatBytes;
  const formatDate = core.formatDate;
  const formatTemplate = core.formatTemplate;
  const getJobStatus = core.getJobStatus;
  const htmlNotice = core.htmlNotice;
  const isMissingJobStatus = core.isMissingJobStatus;
  const jobProgress = core.jobProgress;
  const loadRecentJobs = core.loadRecentJobs;
  const registerWorkflow = core.registerWorkflow;
  const rememberJob = core.rememberJob;
  const request = core.request;
  const responseError = core.responseError;
  const t = core.t;

  // Prune: configure -> scan -> review -> approve -> delete -> complete.
  let pruneToken = '';
  let pruneManifest = '';
  let pruneTotal = 0;
  let pruneRunning = false;
  let pruneReviewData = null;
  let manifestPage = 1;
  let manifestPages = 1;
  let manifestTimer = null;
  let manifestLoaded = false;
  let manifestRequestGeneration = 0;
	let pruneControlsLocked = false;

  const $pruneProg = $('#yotm_progress');
  const $pruneBar = $('#yotm_progress .bar');
  const $pruneStat = $('#yotm_status');
  const $pruneResults = $('#yotm_results');
  const $pruneRun = $('#yotm_run');
  const $pruneCancel = $('#yotm_cancel');
  const $pruneReview = $('#yotm_review_panel');
  const $pruneApprove = $('#yotm_approve_delete');

  function setPruneStep(step) {
    const order = ['configure', 'scanning', 'review', 'deleting', 'completed'];
    const current = order.indexOf(step);
    $('#yotm_prune_steps li').each(function(){
      const index = order.indexOf($(this).data('step'));
      $(this).toggleClass('is-complete', index < current).toggleClass('is-active', index === current);
      if (index === current) {
        $(this).attr('aria-current', 'step');
      } else {
        $(this).removeAttr('aria-current');
      }
    });
  }

  function lockPruneControls(locked) {
	pruneControlsLocked = !!locked;
    $('.yotm_keep, #yotm_discover_orphans, #yotm_discover_historical, input[name="yotm_prune_scope"], #yotm_subpath_search, #yotm_subpath_select_visible, #yotm_subpath_clear').prop('disabled', pruneControlsLocked);
	refreshSubpathHierarchy();
  }

  function pruneProgress(percent, label, indeterminate) {
    const value = Math.max(0, Math.min(100, parseFloat(percent) || 0));
    $pruneProg.show().toggleClass('indeterminate', !!indeterminate).attr('aria-valuenow', value.toFixed(0));
    $pruneProg.attr('aria-busy', indeterminate ? 'true' : 'false');
    $pruneBar.css('width', Math.max(1, value) + '%');
    $pruneStat.show().text(label || value.toFixed(1) + '%');
  }

  function clearPruneJob(options) {
    const opts = options || {};
    pruneRunning = false;
    manifestRequestGeneration += 1;
    manifestLoaded = false;
    $('#yotm_review_confirm').prop('checked', false).prop('disabled', true);
    $pruneApprove.prop('disabled', true);
    if (!opts.preserveJob) {
      pruneToken = '';
      pruneManifest = '';
      pruneTotal = 0;
      pruneReviewData = null;
      forgetJob('prune');
      lockPruneControls(false);
      $pruneCancel.addClass('yo-hidden').prop('disabled', false);
    } else {
      lockPruneControls(true);
      $pruneCancel.removeClass('yo-hidden').prop('disabled', false);
    }
    $pruneRun.prop('disabled', !!opts.preserveJob);
    if (!opts.keepReview) {
      $pruneReview.addClass('yo-hidden');
    }
    if (!opts.keepProgress) {
      $pruneProg.hide().removeClass('indeterminate').attr({'aria-valuenow': '0', 'aria-busy': 'false'});
      $pruneBar.css('width', '0');
      $pruneStat.hide().text('');
    }
    if (!opts.keepResults) {
      $pruneResults.empty();
    }
  }

  function gatherKeep() {
    return $('.yotm_keep:checked').map(function(){ return this.value; }).get();
  }

	function gatherPruneSubpaths() {
		if ($('input[name="yotm_prune_scope"]:checked').val() !== 'selected') {
			return [];
		}

		const selected = $('.yotm_subpath_option:checked').map(function(){ return this.value; }).get();
		selected.sort(function(left, right){
			const leftDepth = (left.match(/\//g) || []).length;
			const rightDepth = (right.match(/\//g) || []).length;
			return leftDepth === rightDepth ? left.localeCompare(right) : leftDepth - rightDepth;
		});

		return selected.filter(function(path, index, paths){
			return !paths.slice(0, index).some(function(parent){ return path.indexOf(parent + '/') === 0; });
		});
	}

	function refreshSubpathHierarchy() {
		$('.yotm_subpath_option[data-ancestor]').each(function(){
			const ancestor = $(this).data('ancestor');
			const covered = $('.yotm_subpath_option[data-kind="parent"][value="' + ancestor + '"]').is(':checked');
			$(this).prop('disabled', pruneControlsLocked || covered).closest('.yo-subpath-option').toggleClass('is-covered', covered);
		});
		$('.yotm_subpath_option[data-kind="parent"]').prop('disabled', pruneControlsLocked);
		$('#yotm_subpath_chips button').prop('disabled', pruneControlsLocked);
	}

	function updateSubpathSelection() {
		const paths = gatherPruneSubpaths();
		let label = t('noFolderSelected', 'Choose at least one folder or use All uploads.');
		if (paths.length === 1) {
			label = t('oneFolderSelected', '1 folder selected');
		} else if (paths.length > 1) {
			label = String(t('foldersSelected', '%s folders selected')).replace('%s', paths.length);
		}

		$('#yotm_subpath_selection_count').text(label);
		const chips = paths.map(function(path){
			return '<button type="button" class="yo-subpath-chip" data-path="' + escapeHtml(path) + '" aria-label="' + escapeHtml(t('removeFolder', 'Remove folder') + ' uploads/' + path) + '"><span>uploads/' + escapeHtml(path) + '/</span><span aria-hidden="true">×</span></button>';
		});
		$('#yotm_subpath_chips').html(chips.join(''));
		refreshSubpathHierarchy();
	}

	function toggleSubpathPicker() {
		const selectedMode = $('input[name="yotm_prune_scope"]:checked').val() === 'selected';
		$('#yotm_subpath_picker').toggleClass('yo-hidden', !selectedMode);
		if (selectedMode) {
			updateSubpathSelection();
		}
	}

	function filterSubpathOptions() {
		const term = String($('#yotm_subpath_search').val() || '').trim().toLowerCase();
		let visibleGroups = 0;
		$('.yo-subpath-group').each(function(){
			const $group = $(this);
			let visibleOptions = 0;
			$group.find('.yo-subpath-option').each(function(){
				const matches = !term || String($(this).data('search') || '').indexOf(term) !== -1;
				$(this).toggleClass('yo-filtered-out', !matches);
				if (matches) {
					visibleOptions += 1;
				}
			});
			$group.toggleClass('yo-filtered-out', visibleOptions === 0).prop('open', !!term && visibleOptions > 0);
			if (visibleOptions > 0) {
				visibleGroups += 1;
			}
		});
		$('#yotm_subpath_no_results').toggleClass('yo-hidden', visibleGroups > 0);
	}

	$('input[name="yotm_prune_scope"]').on('change', toggleSubpathPicker);
	$('#yotm_subpath_search').on('input', filterSubpathOptions);
	$(document).on('change', '.yotm_subpath_option', function(){
		if ($(this).data('kind') === 'parent' && this.checked) {
			$('.yotm_subpath_option[data-ancestor="' + this.value + '"]').prop('checked', false);
		}
		refreshSubpathHierarchy();
		updateSubpathSelection();
	});
	$('#yotm_subpath_select_visible').on('click', function(){
		$('.yo-subpath-group:not(.yo-filtered-out)').each(function(){
			$(this).find('.yo-subpath-option:not(.yo-filtered-out) .yotm_subpath_option').prop('checked', true);
		});
		$('.yotm_subpath_option[data-kind="parent"]:checked').each(function(){
			$('.yotm_subpath_option[data-ancestor="' + this.value + '"]').prop('checked', false);
		});
		updateSubpathSelection();
	});
	$('#yotm_subpath_clear').on('click', function(){
		$('.yotm_subpath_option').prop('checked', false);
		updateSubpathSelection();
	});
	$(document).on('click', '.yo-subpath-chip', function(){
		$('.yotm_subpath_option[value="' + $(this).data('path') + '"]').prop('checked', false);
		updateSubpathSelection();
	});

  function renderOrphanSummary(summary, classCounts) {
    if (!summary) {
      return '';
    }
    const found = summary.found ? Object.keys(summary.found).length : 0;
    const marked = Array.isArray(summary.delete) ? summary.delete.length : 0;
    classCounts = classCounts || {};
    const historicalPreserved = (summary.historical_below_threshold || 0) + (summary.historical_shape_preserved || 0) + (summary.historical_ambiguous || 0);
    if (!found && !marked && !summary.unmapped && !summary.skipped_original && !summary.protected_sources && !summary.source_errors && !summary.unverified_sidecars && !summary.ambiguous_siblings && !summary.malformed_preserved && !summary.kept_dimension_preserved && !historicalPreserved && !classCounts.metadata_backed && !classCounts.verified_legacy && !classCounts.verified_historical_legacy) {
      return '';
    }
    return '<div class="notice notice-info inline"><p><strong>' + escapeHtml(t('orphanDiscovery', 'Orphan discovery:')) + '</strong> ' + found + ' ' + escapeHtml(t('distinctDimsFound', 'distinct dimensions found.')) + '<br><strong>' + escapeHtml(t('metadataBackedCandidates', 'Metadata-backed candidates:')) + '</strong> ' + escapeHtml(classCounts.metadata_backed || 0) + '<br><strong>' + escapeHtml(t('verifiedCurrentLegacyCandidates', 'Verified legacy — currently disabled size:')) + '</strong> ' + escapeHtml(classCounts.verified_legacy_current || 0) + '<br><strong>' + escapeHtml(t('verifiedHistoricalCandidates', 'Verified historical legacy — size no longer registered:')) + '</strong> ' + escapeHtml(classCounts.verified_historical_legacy || 0) + '<br><strong>' + escapeHtml(t('historicalPreserved', 'Ambiguous/unverified historical-looking files preserved:')) + '</strong> ' + escapeHtml(historicalPreserved) + '<br><strong>' + escapeHtml(t('metadataOrphanDimsDelete', 'Metadata orphan dimensions marked for deletion:')) + '</strong> ' + marked + '<br><strong>' + escapeHtml(t('originalFilesProtected', 'Original files protected:')) + '</strong> ' + escapeHtml(summary.skipped_original || 0) + '<br><strong>' + escapeHtml(t('protectedSources', 'Authoritative source paths protected:')) + '</strong> ' + escapeHtml(summary.protected_sources || 0) + '<br><strong>' + escapeHtml(t('sourceErrors', 'Indeterminate source checks preserved:')) + '</strong> ' + escapeHtml(summary.source_errors || 0) + '<br><strong>' + escapeHtml(t('unverifiedSidecars', 'Unverified format sidecars preserved:')) + '</strong> ' + escapeHtml(summary.unverified_sidecars || 0) + '<br><strong>' + escapeHtml(t('ambiguousSiblings', 'Ambiguous sibling files preserved:')) + '</strong> ' + escapeHtml(summary.ambiguous_siblings || 0) + '<br><strong>' + escapeHtml(t('malformedPreserved', 'Malformed or mismatched files preserved:')) + '</strong> ' + escapeHtml(summary.malformed_preserved || 0) + '<br><strong>' + escapeHtml(t('keptDimensionPreserved', 'Files matching a kept size preserved:')) + '</strong> ' + escapeHtml(summary.kept_dimension_preserved || 0) + '<br><strong>' + escapeHtml(t('unmappedDiskSkipped', 'Unmapped disk candidates skipped:')) + '</strong> ' + escapeHtml(Math.max(summary.unmapped || 0, summary.unmapped_skipped || 0)) + '</p></div>';
  }

  function loadManifest(page) {
    if (!pruneToken) {
      return;
    }
    const requestToken = pruneToken;
    const requestGeneration = ++manifestRequestGeneration;
    manifestLoaded = false;
    $('#yotm_review_confirm').prop('checked', false).prop('disabled', true);
    $pruneApprove.prop('disabled', true);
    $('#yotm_manifest_body').html('<tr><td colspan="4">' + escapeHtml(t('manifestLoading', 'Loading manifest…')) + '</td></tr>');
    request('yotm_job_items', {
      token: requestToken,
      page: page || 1,
      per_page: 25,
      search: $('#yotm_manifest_search').val() || ''
    }).done(function(response){
      if (requestGeneration !== manifestRequestGeneration || requestToken !== pruneToken) {
        return;
      }
      if (!response || !response.success || !response.data) {
        $('#yotm_manifest_body').html('<tr><td colspan="4">' + escapeHtml(t('manifestLoadFailed', 'Could not load the manifest.')) + '</td></tr>');
        return;
      }
      const data = response.data;
      manifestPage = parseInt(data.page || 1, 10);
      manifestPages = parseInt(data.pages || 1, 10);
      const $body = $('#yotm_manifest_body').empty();
      if (!Array.isArray(data.items) || !data.items.length) {
        $body.append('<tr><td colspan="4">' + escapeHtml(t('manifestEmpty', 'No manifest items match this filter.')) + '</td></tr>');
      } else {
        data.items.forEach(function(item){
          const proof = Array.isArray(item.ownership_evidence) && item.ownership_evidence.length ? item.ownership_evidence[0] : {};
          const attachmentId = proof.attachment_id || item.attachment_id;
          const attachment = attachmentId ? '#' + attachmentId : '—';
          const legacy = item.ownership_schema === 'legacy_generated_v1';
          const historical = item.ownership_schema === 'historical_legacy_generated_v1';
          const evidence = historical
            ? [t('verifiedHistoricalEvidence', 'Verified historical legacy / no longer registered'), item.observed_width && item.observed_height ? item.observed_width + '×' + item.observed_height : '', item.observed_mime, item.size].filter(Boolean).join(' / ')
            : legacy
            ? [t('verifiedLegacyEvidence', 'Verified legacy disk-only evidence'), item.observed_width && item.observed_height ? item.observed_width + '×' + item.observed_height : '', item.observed_mime, Array.isArray(item.matched_remove_sizes) ? item.matched_remove_sizes.join(', ') : ''].filter(Boolean).join(' / ')
            : (attachmentId ? [t('attachmentMetadata', 'Attachment metadata'), '#' + attachmentId, proof.size || item.size, proof.filename].filter(Boolean).join(' / ') : '—');
          $body.append('<tr><td><code>' + escapeHtml(item.path || t('unknownPath', 'Unknown item')) + '</code></td><td>' + escapeHtml(attachment) + '</td><td>' + escapeHtml(evidence) + '</td><td>' + escapeHtml(formatBytes(item.estimated_bytes || item.bytes)) + '</td></tr>');
        });
      }
      $('#yotm_manifest_count').text(data.total + ' ' + t('matchedFiles', 'matched files'));
      $('#yotm_manifest_page').text(formatTemplate(t('pageOf', 'Page %1$s of %2$s'), {'%1$s': manifestPage, '%2$s': manifestPages}));
      $('#yotm_manifest_prev').prop('disabled', manifestPage <= 1);
      $('#yotm_manifest_next').prop('disabled', manifestPage >= manifestPages);
      manifestLoaded = true;
      $('#yotm_review_confirm').prop('disabled', false);
    }).fail(function(){
      if (requestGeneration !== manifestRequestGeneration || requestToken !== pruneToken) {
        return;
      }
      $('#yotm_manifest_body').html('<tr><td colspan="4">' + escapeHtml(t('manifestLoadFailed', 'Could not load the manifest.')) + '</td></tr>');
    });
  }

  function renderPruneReview(data) {
    pruneReviewData = data;
    pruneTotal = parseInt(data.total || 0, 10);
    pruneManifest = data.manifest_hash || '';
    $('#yotm_review_count').text(pruneTotal.toLocaleString());
    $('#yotm_review_size').text(data.estimated_bytes_human || formatBytes(data.estimated_bytes));
    $('#yotm_review_scope').text(data.scan_base || (data.context && data.context.scan_base_label) || 'uploads/');
    $('#yotm_review_expiry').text(formatDate(data.expires_at));
    $('#yotm_review_hash').text(pruneManifest);
    $('#yotm_review_orphans').html(renderOrphanSummary(data.orphan_summary || (data.context && data.context.orphan_summary), data.manifest_class_counts || (data.context && data.context.manifest_class_counts)));
    manifestLoaded = false;
    $('#yotm_review_confirm').prop('checked', false).prop('disabled', true);
    $pruneApprove.text(String(t('deleteReviewedCount', 'Delete %s reviewed files')).replace('%s', pruneTotal.toLocaleString())).prop('disabled', true).removeClass('yo-hidden');
    $('#yotm_manifest_search').val('').prop('disabled', false);
    $('#yotm_manifest_prev, #yotm_manifest_next').prop('disabled', false);
    $pruneReview.removeClass('yo-hidden');
    setPruneStep('review');
    loadManifest(1);
    window.setTimeout(function(){ $pruneReview.trigger('focus'); }, 50);
  }

  $('#yotm_manifest_prev').on('click', function(){ if (manifestPage > 1) { loadManifest(manifestPage - 1); } });
  $('#yotm_manifest_next').on('click', function(){ if (manifestPage < manifestPages) { loadManifest(manifestPage + 1); } });
  $('#yotm_manifest_search').on('input', function(){
    window.clearTimeout(manifestTimer);
    manifestTimer = window.setTimeout(function(){ loadManifest(1); }, 250);
  });
  $('#yotm_review_confirm').on('change', function(){ $pruneApprove.prop('disabled', !manifestLoaded || !this.checked); });

  function preparePrune() {
    if (pruneRunning || pruneToken) {
      return;
    }
	const limitSubpaths = gatherPruneSubpaths();
	if ($('input[name="yotm_prune_scope"]:checked').val() === 'selected' && !limitSubpaths.length) {
		$pruneResults.html(htmlNotice('notice-error', t('noFolderSelected', 'Choose at least one folder or use All uploads.'))).trigger('focus');
		return;
	}
    pruneRunning = true;
    setPruneStep('scanning');
    lockPruneControls(true);
    $pruneRun.prop('disabled', true);
    $pruneCancel.removeClass('yo-hidden');
    $pruneReview.addClass('yo-hidden');
    $pruneResults.empty();
    pruneProgress(1, t('scanning', 'Scanning…'), true);
    request('yotm_prune_prepare', {
      keep: gatherKeep(),
	  limit_subpaths: limitSubpaths,
      discover_orphans: $('#yotm_discover_orphans').is(':checked') ? 1 : 0,
      discover_historical: $('#yotm_discover_historical').is(':checked') ? 1 : 0
    }).done(function(response){
      if (!response || !response.success || !response.data) {
        const resumeToken = response && response.data ? response.data.resume_token : '';
        if (resumeToken) {
          resumePrune(resumeToken);
          return;
        }
        $pruneResults.html(htmlNotice('notice-error', t('prepareFailed', 'Prepare failed:') + ' ' + responseError(response, t('unknownError', 'Unknown error'))));
        clearPruneJob({keepResults: true});
        setPruneStep('configure');
        return;
      }
      pruneToken = response.data.token;
      rememberJob('prune', pruneToken);
      scanPruneBatch();
      loadRecentJobs();
    }).fail(function(){
      $pruneResults.html(htmlNotice('notice-error', t('networkPrepare', 'Network error during prepare.')));
      clearPruneJob({keepResults: true, preserveJob: !!pruneToken});
    });
  }

  function scanPruneBatch() {
    const token = pruneToken;
    if (!token || !pruneRunning) {
      return;
    }
    request('yotm_prune_scan_batch', {token: token, batch: 100}).done(function(response){
      if (token !== pruneToken || !pruneRunning) {
        return;
      }
      if (!response || !response.success || !response.data) {
        $pruneResults.prepend(htmlNotice('notice-error', t('scanFailed', 'Scan failed:') + ' ' + responseError(response, t('unknownError', 'Unknown error'))));
        clearPruneJob({keepResults: true, preserveJob: true});
        return;
      }
      const data = response.data;
      if (data.stopped || data.status === 'cancelled' || data.status === 'expired') {
        $pruneResults.prepend(htmlNotice('notice-warning', t('jobStopped', 'Job stopped.')));
        clearPruneJob({keepResults: true});
        setPruneStep('configure');
        loadRecentJobs();
        return;
      }
      let label = t('scanning', 'Scanning…') + ' ' + data.scan_processed + ' / ' + data.scan_total_attachments;
      let indeterminate = false;
      if (data.scan_phase === 'disk') {
        label = String(t('scanningDiskCount', 'Scanning uploads folders… %s entries checked')).replace('%s', data.disk_entries_processed || 0);
        indeterminate = true;
      } else if (data.scan_phase === 'source_index') {
        label = t('indexingSources', 'Indexing authoritative media sources…');
        indeterminate = true;
      } else if (data.scan_phase === 'selection' && data.total_known === false) {
        label = String(t('scanningAttachmentRows', 'Scanning attachment rows… %s checked')).replace('%s', data.selection_scanned || 0);
        indeterminate = true;
      } else if (data.scan_phase === 'manifest') {
        label = t('buildingManifest', 'Building immutable manifest…');
        indeterminate = true;
      }
      pruneProgress(data.scan_percent || 0, label, indeterminate);
      if (!data.scan_done) {
        window.setTimeout(scanPruneBatch, parseInt(data.retry_after_ms || 120, 10));
        return;
      }
      pruneRunning = false;
      if (!parseInt(data.total || 0, 10)) {
        $pruneResults.html(htmlNotice('notice-warning', t('noMatchingThumbnails', 'No matching thumbnails found.')));
        clearPruneJob({keepResults: true});
        setPruneStep('completed');
        loadRecentJobs();
        $pruneResults.trigger('focus');
        return;
      }
      $pruneResults.html(htmlNotice('notice-info', t('scanReadyForReview', 'Scan complete. Review the immutable manifest below; nothing has been deleted.')));
      $pruneProg.hide();
      $pruneStat.hide();
      $pruneCancel.removeClass('yo-hidden');
      renderPruneReview(data);
      loadRecentJobs();
    }).fail(function(request){
      if (token !== pruneToken) {
        return;
      }
      const status = parseInt(request && request.status || 0, 10);
      const message = status
        ? t('scanFailed', 'Scan failed:') + ' ' + responseError(request, t('unknownError', 'Unknown error'))
        : t('networkScan', 'Network error during scan.');
      $pruneResults.prepend(htmlNotice('notice-error', message + ' ' + t('resumeAfterNetworkError', 'Reload this page to resume it.')));
      clearPruneJob({keepResults: true, preserveJob: true});
    });
  }

  function approvePruneManifest() {
    if (!pruneToken || !pruneManifest || !manifestLoaded || !$('#yotm_review_confirm').is(':checked')) {
      return;
    }
    $pruneApprove.prop('disabled', true);
    $('#yotm_review_confirm, #yotm_manifest_search, #yotm_manifest_prev, #yotm_manifest_next').prop('disabled', true);
    request('yotm_prune_approve', {
      token: pruneToken, manifest_hash: pruneManifest, confirmed: 1
    }).done(function(response){
      if (!response || !response.success) {
        $pruneResults.prepend(htmlNotice('notice-error', t('approvalFailed', 'Delete approval failed:') + ' ' + responseError(response, t('unknownError', 'Unknown error'))));
        $('#yotm_review_confirm').prop('disabled', !manifestLoaded);
        $('#yotm_manifest_search, #yotm_manifest_prev, #yotm_manifest_next').prop('disabled', false);
        $pruneApprove.prop('disabled', !manifestLoaded || !$('#yotm_review_confirm').is(':checked'));
        return;
      }
      pruneRunning = true;
      setPruneStep('deleting');
      pruneProgress(0, t('deleting', 'Deleting…'));
      deletePruneBatch();
      loadRecentJobs();
    }).fail(function(){
      $pruneResults.prepend(htmlNotice('notice-error', t('networkApproval', 'Network error while approving the manifest.')));
      $('#yotm_review_confirm').prop('disabled', !manifestLoaded);
      $('#yotm_manifest_search, #yotm_manifest_prev, #yotm_manifest_next').prop('disabled', false);
      $pruneApprove.prop('disabled', !manifestLoaded || !$('#yotm_review_confirm').is(':checked'));
    });
  }

  function deletePruneBatch() {
    const token = pruneToken;
    if (!token || !pruneRunning) {
      return;
    }
    request('yotm_prune_delete_batch', {
      token: token, manifest_hash: pruneManifest, batch: 100
    }).done(function(response){
      if (token !== pruneToken || !pruneRunning) {
        return;
      }
      if (!response || !response.success || !response.data) {
        $pruneResults.prepend(htmlNotice('notice-error', t('deleteFailed', 'Delete failed:') + ' ' + responseError(response, t('unknownError', 'Unknown error'))));
        clearPruneJob({keepResults: true, keepReview: true, preserveJob: true});
        return;
      }
      const data = response.data;
      if (data.stopped || data.status === 'cancelled') {
        $pruneResults.prepend(htmlNotice('notice-warning', t('jobStopped', 'Job stopped. Completed work was not rolled back.')));
        clearPruneJob({keepResults: true});
        setPruneStep('configure');
        loadRecentJobs();
        return;
      }
      pruneProgress(data.percent || 0, t('deleting', 'Deleting…') + ' ' + data.processed + ' / ' + pruneTotal + ' — ' + data.bytes_human);
      if (!data.done) {
        window.setTimeout(deletePruneBatch, parseInt(data.retry_after_ms || 120, 10));
        return;
      }
      let message = formatTemplate(t('doneDeleted', 'Done. Deleted %1$s files — Freed %2$s.'), {'%1$s': data.deleted, '%2$s': data.bytes_human});
      if (data.failed) {
        message += ' ' + t('failedFiles', 'Failed files:') + ' ' + data.failed;
      }
      $pruneResults.html(htmlNotice(data.failed ? 'notice-warning' : 'notice-success', message) + errorDetails(data.errors));
      clearPruneJob({keepResults: true, keepProgress: true});
      setPruneStep('completed');
      $pruneResults.trigger('focus');
      loadRecentJobs();
    }).fail(function(){
      if (token !== pruneToken) {
        return;
      }
      $pruneResults.prepend(htmlNotice('notice-error', t('networkDelete', 'Network error during delete.') + ' ' + t('resumeAfterNetworkError', 'Reload this page to resume it.')));
      clearPruneJob({keepResults: true, keepReview: true, preserveJob: true});
    });
  }

  function resumePrune(token) {
    if (!token || (pruneToken === token && (pruneRunning || !$pruneReview.hasClass('yo-hidden')))) {
      return;
    }
    pruneToken = token;
    pruneRunning = true;
    rememberJob('prune', token);
    activateTab('prune');
    lockPruneControls(true);
    $pruneRun.prop('disabled', true);
    $pruneCancel.removeClass('yo-hidden');
    $pruneResults.html(htmlNotice('notice-info', t('resumeAvailable', 'An unfinished job was found. Resuming it now…')));
    getJobStatus(token).done(function(response){
      if (pruneToken !== token) {
        return;
      }
      if (!response || !response.success || !response.data) {
        clearPruneJob({keepResults: true});
        setPruneStep('configure');
        return;
      }
      const data = response.data;
      pruneManifest = data.manifest_hash || '';
      pruneTotal = parseInt(data.total || 0, 10);
      if (data.status === 'approved' || data.status === 'deleting') {
        setPruneStep('deleting');
        pruneProgress(jobProgress(data), t('deleting', 'Deleting…'));
        deletePruneBatch();
      } else if (data.status === 'scanning') {
        setPruneStep('scanning');
        pruneProgress(jobProgress(data), t('scanning', 'Scanning…'), true);
        scanPruneBatch();
      } else if (data.status === 'awaiting_approval') {
        setPruneStep('scanning');
        scanPruneBatch();
      } else {
        clearPruneJob({keepResults: true});
        setPruneStep(data.status === 'completed' ? 'completed' : 'configure');
      }
    }).fail(function(request){
      if (pruneToken !== token) {
        return;
      }
      if (isMissingJobStatus(request)) {
        clearPruneJob({keepResults: true});
        setPruneStep('configure');
        return;
      }
      clearPruneJob({keepResults: true, preserveJob: true});
    });
  }

  $pruneRun.on('click', preparePrune);
  $pruneApprove.on('click', approvePruneManifest);
  $pruneCancel.on('click', function(){
    if (!pruneToken) {
      clearPruneJob({keepResults: true});
      return;
    }
    const token = pruneToken;
    pruneRunning = false;
    $pruneCancel.prop('disabled', true);
    $pruneStat.show().text(t('stopping', 'Stopping after the current batch…'));
    cancelJob(token).done(function(){
      if (pruneToken !== token) {
        return;
      }
      $pruneResults.prepend(htmlNotice('notice-warning', t('jobStopped', 'Job stopped. Completed work was not rolled back, and the audit record was retained.')));
      clearPruneJob({keepResults: true});
      setPruneStep('configure');
      $pruneResults.trigger('focus');
      loadRecentJobs();
    }).fail(function(){
      if (pruneToken !== token) {
        return;
      }
      pruneRunning = true;
      $pruneCancel.prop('disabled', false);
      $pruneResults.prepend(htmlNotice('notice-error', t('unknownError', 'Unknown error')));
    });
  });

  toggleSubpathPicker();
  filterSubpathOptions();
  registerWorkflow('prune', {
    isActive: function(){ return !!pruneToken; },
    resume: resumePrune
  });
})(jQuery, window.YOTMAdmin);
