(function($){
  const i18n = (window.YOTM && YOTM.i18n) ? YOTM.i18n : {};

  function t(key, fallback) {
    return i18n[key] || fallback;
  }

  function activateTab(tab) {
    $('#yotm_tabs .yo-tab').removeClass('active');
    $('#yotm_tabs .yo-tab').attr({'aria-selected': 'false', 'tabindex': '-1'});
    $('#yotm_tabs .yo-tab[data-tab="' + tab + '"]').addClass('active').attr({'aria-selected': 'true', 'tabindex': '0'});
    $('.yo-panel').removeClass('active').attr('hidden', true);
    $('#yotm_panel_' + tab).addClass('active').removeAttr('hidden');
  }

  // Tabs
  $('#yotm_tabs .yo-tab').on('click', function(){
    activateTab($(this).data('tab'));
  });

  $('#yotm_tabs .yo-tab').on('keydown', function(e){
    const keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];
    if (!keys.includes(e.key)) {
      return;
    }

    e.preventDefault();
    const $tabs = $('#yotm_tabs .yo-tab');
    const index = $tabs.index(this);
    let next = index;

    if (e.key === 'ArrowLeft') {
      next = index <= 0 ? $tabs.length - 1 : index - 1;
    } else if (e.key === 'ArrowRight') {
      next = index >= $tabs.length - 1 ? 0 : index + 1;
    } else if (e.key === 'Home') {
      next = 0;
    } else if (e.key === 'End') {
      next = $tabs.length - 1;
    }

    const $next = $tabs.eq(next);
    activateTab($next.data('tab'));
    $next.trigger('focus');
  });

  // Quick toggles in Sizes tab
  const coreSet = new Set(['thumbnail','medium','large','medium_large','1536x1536','2048x2048']);

  $('#yotm_sizes_enable_core').on('click', function(){
    $('input[name="yotm_enable_sizes[]"]').each(function(){
      this.checked = coreSet.has(this.value);
    });
  });

  $('#yotm_sizes_enable_all').on('click', function(){
    $('input[name="yotm_enable_sizes[]"]').prop('checked', true);
  });

  $('#yotm_sizes_disable_all').on('click', function(){
    $('input[name="yotm_enable_sizes[]"]').prop('checked', false);
  });

  // Danger zone: prune thumbnails for CURRENTLY disabled sizes
  $('#yotm_sizes_prune_disabled').on('click', function(){
    const enabled = new Set();

    $('input[name="yotm_enable_sizes[]"]').each(function(){
      if (this.checked) {
        enabled.add(this.value);
      }
    });

    const allSizes = [];
    $('.yotm_keep').each(function(){
      allSizes.push(this.value);
    });

    if (enabled.size === allSizes.length) {
      alert(t('allSizesEnabled', 'All sizes are enabled — there are no disabled sizes to prune.'));
      return;
    }

    if (!confirm(t('confirmPruneDisabled', 'This will delete thumbnails for all currently DISABLED sizes. Proceed?'))) {
      return;
    }

    activateTab('prune');

    $('.yotm_keep').each(function(){
      this.checked = enabled.has(this.value);
    });

    $('#yotm_discover_orphans').prop('checked', false);
    $('input[name="yotm_mode"][value="delete"]').prop('checked', true);

    $('#yotm_run').trigger('click');
  });

  const ajaxurl = (window.YOTM && YOTM.ajaxurl) ? YOTM.ajaxurl : (window.ajaxurl || '');
  const nonce   = (window.YOTM && YOTM.nonce) ? YOTM.nonce : '';

  function escapeHtml(str) {
    return $('<div>').text(str == null ? '' : String(str)).html();
  }

  function htmlNotice(cls, txt){
    const safeClass = String(cls || '').replace(/[^a-z0-9_\-\s]/ig, '');
    return '<div class="notice ' + safeClass + '"><p>' + escapeHtml(txt) + '</p></div>';
  }

  // =========================
  // === PRUNE JS ============
  // =========================
  let token = null;
  let total = 0;
  let deleted = 0;
  let bytes = 0;
  let cancelFlag = false;

  const $bar    = $('#yotm_progress .bar');
  const $prog   = $('#yotm_progress');
  const $stat   = $('#yotm_status');
  const $res    = $('#yotm_results');
  const $run    = $('#yotm_run');
  const $cancel = $('#yotm_cancel');

  function setIndeterminate(on){
    if (on){
      $prog.addClass('indeterminate').attr({'aria-valuenow': '0', 'aria-busy': 'true'}).show();
      $bar.css('width', '30%');
      $stat.show().text(t('scanning', 'Scanning…'));
    } else {
      $prog.removeClass('indeterminate').removeAttr('aria-busy');
    }
  }

  function setProgress(p, label){
    const value = Math.max(0, Math.min(100, p));
    $prog.show();
    $prog.attr('aria-valuenow', value.toFixed(0));
    $bar.css('width', Math.max(1, value) + '%');
    $stat.show().text(label || (p.toFixed(1) + '%'));
  }

  function resetUI(opts){
    opts = opts || {};

    token = null;
    total = 0;
    deleted = 0;
    bytes = 0;
    cancelFlag = false;

    if (!opts.keepResults) {
      $res.empty();
    }

    $stat.hide().text('');
    $prog.hide().attr('aria-valuenow', '0').removeAttr('aria-busy');
    $bar.css('width', '0');
    $cancel.addClass('yo-hidden');
    $run.prop('disabled', false);
  }

  function gatherKeep(){
    const keep = [];
    $('.yotm_keep:checked').each(function(){
      keep.push(this.value);
    });
    return keep;
  }

  function renderPruneSummary(data) {
    const keepList = (data.keep && data.keep.length) ? data.keep.map(escapeHtml).join(', ') : '(none)';
    const removeList = (data.remove && data.remove.length) ? data.remove.map(escapeHtml).join(', ') : '(none)';
    const matched = parseInt(data.total || 0, 10);

    let summary = '<p><strong>' + escapeHtml(t('scanBase', 'Scan base:')) + '</strong> ' + escapeHtml(data.scan_base || 'uploads/') + '</p>'
                + '<p><strong>' + escapeHtml(t('keep', 'KEEP:')) + '</strong> ' + keepList + '</p>'
                + '<p><strong>' + escapeHtml(t('deleteNotSelected', 'DELETE (not selected):')) + '</strong> ' + removeList + '</p>'
                + '<p><strong>' + escapeHtml(t('matchedFiles', 'Matched files:')) + '</strong> ' + matched + '</p>';

    if (data.orphan_summary){
      const os = data.orphan_summary;
      const keepMatch = (os.kept_match && os.kept_match.length) ? os.kept_match.slice(0, 30).map(escapeHtml).join(', ') : '(none)';
      const delDims   = (os.delete && os.delete.length) ? os.delete.slice(0, 30).map(escapeHtml).join(', ') : '(none)';
      const skippedOriginal = parseInt(os.skipped_original || 0, 10);
      const unmapped = parseInt(os.unmapped || 0, 10);
      const unmappedSkipped = parseInt(os.unmapped_skipped || 0, 10);

      summary += '<div class="notice notice-info"><p>'
        + '<strong>' + escapeHtml(t('orphanDiscovery', 'Orphan discovery:')) + '</strong> '
        + (os.found ? Object.keys(os.found).length : 0) + ' ' + escapeHtml(t('distinctDimsFound', 'distinct dims on disk/metadata.')) + '<br>'
        + '<strong>' + escapeHtml(t('dimsKeptBySelection', 'Dims kept by selection:')) + '</strong> ' + keepMatch + '<br>'
        + '<strong>' + escapeHtml(t('metadataOrphanDimsDelete', 'Metadata orphan dims marked for deletion:')) + '</strong> ' + delDims
        + (os.delete && os.delete.length > 30 ? ' ...' : '')
        + '<br><strong>' + escapeHtml(t('originalFilesProtected', 'Original files protected:')) + '</strong> ' + skippedOriginal
        + '<br><strong>' + escapeHtml(t('unmappedDiskSkipped', 'Unmapped disk candidates skipped:')) + '</strong> ' + Math.max(unmapped, unmappedSkipped)
        + '</p></div>';
    }

    const sample = (data.sample && data.sample.length)
      ? '<p><strong>' + escapeHtml(t('sampleMatches', 'Sample matches')) + ' (' + data.sample.length + ' ' + escapeHtml(t('shown', 'shown')) + '):</strong></p><textarea class="yo-sample" readonly>'
        + escapeHtml(data.sample.join("\n")) + '</textarea>'
      : '';

    $res.html(summary + sample);
  }

  function prepare(){
    $run.prop('disabled', true);
    $cancel.removeClass('yo-hidden');
    $res.empty();
    setIndeterminate(true);

    $.post(ajaxurl, {
      action: 'yotm_prune_prepare',
      nonce: nonce,
      keep: gatherKeep(),
      mode: $('input[name="yotm_mode"]:checked').val(),
      limit_subpath: $('#yotm_limit_subpath').val() || '',
      discover_orphans: $('#yotm_discover_orphans').is(':checked') ? 1 : 0
    }).done(function(r){
      if (!r || !r.success){
        $res.html(htmlNotice('notice-error', t('prepareFailed', 'Prepare failed:') + ' ' + (r && r.data && r.data.msg ? r.data.msg : t('unknownError', 'Unknown error'))));
        resetUI();
        return;
      }

      setIndeterminate(false);
      token = r.data.token;
      total = 0;
      scanBatch();
    }).fail(function(){
      $res.html(htmlNotice('notice-error', t('networkPrepare', 'Network error during prepare.')));
      resetUI();
    });
  }

  function scanBatch(){
    if (cancelFlag){
      $res.prepend(htmlNotice('notice-warning', t('cancelled', 'Cancelled.')));
      resetUI({ keepResults: true });
      return;
    }

    $.post(ajaxurl, {
      action: 'yotm_prune_scan_batch',
      nonce: nonce,
      token: token,
      batch: 100
    }).done(function(r){
      if (!r || !r.success || !r.data){
        $res.html(htmlNotice('notice-error', t('scanFailed', 'Scan failed:') + ' ' + (r && r.data && r.data.msg ? r.data.msg : t('unknownError', 'Unknown error'))));
        resetUI();
        return;
      }

      const d = r.data;
      const scanPercent = typeof d.scan_percent !== 'undefined' ? parseFloat(d.scan_percent) : 0;
      const scanTotal = parseInt(d.scan_total_attachments || 0, 10);
      const scanProcessed = parseInt(d.scan_processed || 0, 10);

      setProgress(scanPercent, t('scanning', 'Scanning…') + ' ' + scanProcessed + ' / ' + scanTotal + ' (' + scanPercent.toFixed(1) + '%)');

      if (!d.scan_done){
        setTimeout(scanBatch, 120);
        return;
      }

      total = parseInt(d.total || 0, 10);
      renderPruneSummary(d);

      const mode = $('input[name="yotm_mode"]:checked').val();

      if (mode === 'dry'){
        $res.prepend(htmlNotice('notice-info', t('dryRunComplete', 'Dry-run complete. No deletions performed.')));
        resetUI({ keepResults: true });
        return;
      }

      if (total === 0){
        $res.prepend(htmlNotice('notice-warning', t('noMatchingThumbnails', 'No matching thumbnails found. Try enabling orphan discovery or widen the folder scope.')));
        resetUI({ keepResults: true });
        return;
      }

      deleted = 0;
      bytes = 0;
      doBatch();
    }).fail(function(){
      $res.html(htmlNotice('notice-error', t('networkScan', 'Network error during scan.')));
      resetUI();
    });
  }

  function doBatch(){
    if (cancelFlag){
      $res.prepend(htmlNotice('notice-warning', t('cancelled', 'Cancelled.')));
      resetUI({ keepResults: true });
      return;
    }

    setProgress(total ? (deleted / total * 100) : 0, t('deleting', 'Deleting…') + ' ' + deleted + ' / ' + total);

    $.post(ajaxurl, {
      action: 'yotm_prune_delete_batch',
      nonce: nonce,
      token: token,
      batch: 200
    }).done(function(r){
      if (!r || !r.success){
        $res.prepend(htmlNotice('notice-error', t('deleteFailed', 'Delete failed:') + ' ' + (r && r.data && r.data.msg ? r.data.msg : t('unknownError', 'Unknown error'))));
        resetUI({ keepResults: true });
        return;
      }

      deleted = r.data.processed;
      bytes   = r.data.bytes;
      const deletedFiles = typeof r.data.deleted !== 'undefined' ? r.data.deleted : r.data.processed;

      const p = r.data.percent;
      setProgress(p, t('deleting', 'Deleting…') + ' ' + deleted + ' / ' + total + ' (' + p.toFixed(1) + '%) — Freed ' + r.data.bytes_human);

      if (r.data.done){
        $res.prepend(htmlNotice('notice-success', t('doneDeleted', 'Done. Deleted %1$s files — Freed %2$s.').replace('%1$s', deletedFiles).replace('%2$s', r.data.bytes_human)));
        resetUI({ keepResults: true });
        return;
      }

      setTimeout(doBatch, 120);
    }).fail(function(){
      $res.prepend(htmlNotice('notice-error', t('networkDelete', 'Network error during delete.')));
      resetUI({ keepResults: true });
    });
  }

  $('#yotm_run').on('click', function(){
    prepare();
  });

  $('#yotm_cancel').on('click', function(){
    cancelFlag = true;
  });

  // =============================
  // === REGENERATE JS ===========
  // =============================
  let regenToken = null;
  let regenRunning = false;
  let regenCancelled = false;

  const $regenBar    = $('#yotm_regen_progress .bar');
  const $regenProg   = $('#yotm_regen_progress');
  const $regenStat   = $('#yotm_regen_status');
  const $regenRes    = $('#yotm_regen_results');
  const $regenRun    = $('#yotm_regen_run');
  const $regenCancel = $('#yotm_regen_cancel');

  function yotmToggleRegenScopeFields(){
    const scope = $('#yotm_regen_scope').val();
    $('#yotm_regen_subpath_wrap').toggleClass('yo-hidden', scope !== 'subpath');
    $('#yotm_regen_ids_wrap').toggleClass('yo-hidden', scope !== 'ids');
  }

  function regenSetProgress(p, label){
    const value = Math.max(0, Math.min(100, p));
    $regenProg.show();
    $regenProg.attr('aria-valuenow', value.toFixed(0));
    $regenBar.css('width', Math.max(1, value) + '%');
    $regenStat.show().text(label || (p.toFixed(1) + '%'));
  }

  function regenResetUI(opts){
    opts = opts || {};

    regenToken = null;
    regenRunning = false;
    regenCancelled = false;

    if (!opts.keepResults) {
      $regenRes.empty();
    }

    $regenStat.hide().text('');
    $regenProg.hide().attr('aria-valuenow', '0').removeAttr('aria-busy');
    $regenBar.css('width', '0');
    $regenCancel.addClass('yo-hidden');
    $regenRun.prop('disabled', false);
  }

  function regenBatch(){
    if (regenCancelled){
      $regenRes.prepend(htmlNotice('notice-warning', t('regenerationCancelled', 'Regeneration cancelled.')));
      regenResetUI({ keepResults: true });
      return;
    }

    $.post(ajaxurl, {
      action: 'yotm_regenerate_batch',
      nonce: nonce,
      token: regenToken,
      batch: 20
    }).done(function(r){
      if (!r || !r.success || !r.data){
        $regenRes.prepend(htmlNotice('notice-error', t('batchFailed', 'Batch failed:') + ' ' + (r && r.data && r.data.msg ? r.data.msg : t('unknownError', 'Unknown error'))));
        regenResetUI({ keepResults: true });
        return;
      }

      const d = r.data;
      const percent = typeof d.percent !== 'undefined' ? parseFloat(d.percent) : 0;

      regenSetProgress(
        percent,
        t('processing', 'Processing…') + ' ' + d.processed + ' / ' + d.total + ' (' + percent.toFixed(1) + '%)'
      );

      $regenRes.html(
        '<p><strong>' + escapeHtml(t('scope', 'Scope:')) + '</strong> ' + escapeHtml(d.scope_label || 'All media') + '</p>' +
        '<p><strong>' + escapeHtml(t('processed', 'Processed:')) + '</strong> ' + escapeHtml(d.processed) + ' / ' + escapeHtml(d.total) + '</p>' +
        '<p><strong>' + escapeHtml(t('regenerated', 'Regenerated:')) + '</strong> ' + escapeHtml(d.regenerated) + '</p>' +
        '<p><strong>' + escapeHtml(t('skipped', 'Skipped:')) + '</strong> ' + escapeHtml(d.skipped) + '</p>' +
        '<p><strong>' + escapeHtml(t('failed', 'Failed:')) + '</strong> ' + escapeHtml(d.failed) + '</p>'
      );

      if (d.done){
        $regenRes.prepend(
	          htmlNotice(
	            'notice-success',
	            t('doneRegenerated', 'Done. Regenerated %1$s attachments, skipped %2$s, failed %3$s.')
	              .replace('%1$s', d.regenerated)
	              .replace('%2$s', d.skipped)
	              .replace('%3$s', d.failed)
	          )
	        );
        regenResetUI({ keepResults: true });
        return;
      }

      setTimeout(regenBatch, 120);
    }).fail(function(){
      $regenRes.prepend(htmlNotice('notice-error', t('networkRegenerateBatch', 'Network error during regenerate batch.')));
      regenResetUI({ keepResults: true });
    });
  }

  function regenPrepare(opts){
    opts = opts || {};

    if (regenRunning) {
      return;
    }

    if (opts.activateTab) {
      activateTab('regenerate');
    }

    regenRunning = true;
    regenCancelled = false;

    $regenRun.prop('disabled', true);
    $regenCancel.removeClass('yo-hidden');
    $regenRes.empty();
    regenSetProgress(1, t('preparingRegenerationQueue', 'Preparing regeneration queue…'));

    $.post(ajaxurl, {
      action: 'yotm_regenerate_prepare',
      nonce: nonce,
      scope: $('#yotm_regen_scope').val() || 'all',
      subpath: $('#yotm_regen_subpath').val() || '',
      attachment_ids: $('#yotm_regen_attachment_ids').val() || '',
      only_missing: $('#yotm_regen_only_missing').is(':checked') ? 1 : 0,
      force_all: $('#yotm_regen_force_all').is(':checked') ? 1 : 0
    }).done(function(r){
      if (!r || !r.success || !r.data){
        $regenRes.html(htmlNotice('notice-error', t('prepareFailed', 'Prepare failed:') + ' ' + (r && r.data && r.data.msg ? r.data.msg : t('unknownError', 'Unknown error'))));
        regenResetUI();
        return;
      }

      regenToken = r.data.token;

      if (!r.data.total){
        $regenRes.html(htmlNotice('notice-warning', t('noImageAttachments', 'No image attachments found.')));
        regenSetProgress(100, t('noItemsToProcess', 'No items to process.'));
        regenResetUI({ keepResults: true });
        return;
      }

      $regenRes.html(
        '<p><strong>' + escapeHtml(t('scope', 'Scope:')) + '</strong> ' + escapeHtml(r.data.scope_label || 'All media') + '</p>' +
        '<p><strong>' + escapeHtml(t('attachmentsFound', 'Attachments found:')) + '</strong> ' + escapeHtml(r.data.total) + '</p>' +
        '<p><strong>' + escapeHtml(t('onlyGenerateMissing', 'Only generate missing:')) + '</strong> ' + (r.data.only_missing ? escapeHtml(t('yes', 'Yes')) : escapeHtml(t('no', 'No'))) + '</p>' +
        '<p><strong>' + escapeHtml(t('forceRegenerateAll', 'Force regenerate all:')) + '</strong> ' + (r.data.force_all ? escapeHtml(t('yes', 'Yes')) : escapeHtml(t('no', 'No'))) + '</p>'
      );

      regenSetProgress(1, t('starting', 'Starting…') + ' 0 / ' + r.data.total);
      regenBatch();
    }).fail(function(){
      $regenRes.html(htmlNotice('notice-error', t('networkRegeneratePrepare', 'Network error during regenerate prepare.')));
      regenResetUI();
    });
  }

  $(document).on('change', '#yotm_regen_scope', function(){
    yotmToggleRegenScopeFields();
  });

  $('#yotm_regen_run').on('click', function(){
    regenPrepare();
  });

  $('#yotm_regen_cancel').on('click', function(){
    regenCancelled = true;
  });

  // Save changes and run regenerate now
  $(document).on('click', 'button[name="yotm_save_and_regenerate"]', function(){
    const $form = $(this).closest('form');

    if (!$form.length) {
      return;
    }

    if (!$form.find('input[name="yotm_save_and_regenerate"]').length) {
      $('<input>', {
        type: 'hidden',
        name: 'yotm_save_and_regenerate',
        value: '1'
      }).appendTo($form);
    } else {
      $form.find('input[name="yotm_save_and_regenerate"]').val('1');
    }
  });

  yotmToggleRegenScopeFields();

  if (window.YOTM_RUN_REGENERATE_AFTER_SAVE) {
    activateTab('regenerate');
    setTimeout(function(){
      regenPrepare();
    }, 250);
  }

  // ==================================
  // === SMART RECOMMENDATIONS ========
  // ==================================

  const $recommendProgress = $('#yotm_recommend_progress');
  const $recommendBar      = $('#yotm_recommend_progress .bar');
  const $recommendStatus   = $('#yotm_recommend_status');
  const $recommendResults  = $('#yotm_recommend_results');

  function renderRecommendationTable(items) {

    if (!Array.isArray(items) || !items.length) {
      return '<div class="notice notice-warning"><p>' + escapeHtml(t('noRecommendationData', 'No recommendation data found.')) + '</p></div>';
    }

    let html = '';

    html += '<table class="widefat striped">';
    html += '<thead>';
    html += '<tr>';
    html += '<th>' + escapeHtml(t('size', 'Size')) + '</th>';
    html += '<th>' + escapeHtml(t('dimensions', 'Dimensions')) + '</th>';
    html += '<th>' + escapeHtml(t('status', 'Status')) + '</th>';
    html += '<th>' + escapeHtml(t('reason', 'Reason')) + '</th>';
    html += '<th>' + escapeHtml(t('recommendation', 'Recommendation')) + '</th>';
    html += '</tr>';
    html += '</thead>';
    html += '<tbody>';

    items.forEach(function(item){

      let badgeClass = 'yo-warning';

      if (item.status === 'used') {
        badgeClass = 'yo-safe';
      } else if (item.status === 'protected') {
        badgeClass = 'yo-safe';
      } else if (item.status === 'danger') {
        badgeClass = 'yo-danger';
      }

      html += '<tr>';

      html += '<td><strong>' + escapeHtml(item.name || '') + '</strong></td>';

      html += '<td>' + escapeHtml(item.dimensions || '—') + '</td>';

      html += '<td>';
      html += '<span class="yo-badge ' + badgeClass + '">';
      html += escapeHtml(item.label || item.status || t('unknown', 'Unknown'));
      html += '</span>';
      html += '</td>';

      html += '<td>' + escapeHtml(item.reason || '') + '</td>';

      html += '<td>' + escapeHtml(item.recommendation || '') + '</td>';

      html += '</tr>';

    });

    html += '</tbody>';
    html += '</table>';

    return html;
  }

  $('#yotm_recommend_scan').on('click', function(){

    $recommendResults.empty();

    $recommendProgress.show().attr('aria-valuenow', '20');
    $recommendBar.css('width', '20%');

    $recommendStatus
      .show()
      .text(t('scanningMediaUsage', 'Scanning media usage…'));

    $.post(ajaxurl, {
      action: 'yotm_recommend_scan',
      nonce: nonce
    }).done(function(r){

      if (!r || !r.success || !r.data) {

        $recommendResults.html(
          htmlNotice(
            'notice-error',
            t('recommendationScanFailed', 'Recommendation scan failed.')
          )
        );

        $recommendProgress.hide().attr('aria-valuenow', '0');

        return;
      }

      $recommendBar.css('width', '100%');
      $recommendProgress.attr('aria-valuenow', '100');

      $('#yotm_recommend_keep_count').text(r.data.keep_count || 0);
      $('#yotm_recommend_unused_count').text(r.data.unused_count || 0);
      $('#yotm_recommend_protected_count').text(r.data.protected_count || 0);
      $('#yotm_recommend_savings').text(r.data.savings || '—');
      
      window.YOTM_RECOMMENDED_KEEP = r.data.recommended_keep || [];

      $recommendResults.html(
        renderRecommendationTable(r.data.items || [])
      );

      $('#yotm_apply_recommendations').removeClass('yo-hidden');
      $('#yotm_recommend_go_prune').removeClass('yo-hidden');

      $recommendStatus.text(t('recommendationScanCompleted', 'Scan completed.'));

    }).fail(function(){

      $recommendResults.html(
        htmlNotice(
          'notice-error',
          t('networkRecommendationScan', 'Network error during recommendation scan.')
        )
      );

      $recommendProgress.hide().attr('aria-valuenow', '0');

    });

  });

  $('#yotm_apply_recommendations').on('click', function(){

    if (!window.YOTM_RECOMMENDED_KEEP) {
      return;
    }

    $('input[name="yotm_enable_sizes[]"]').prop('checked', false);

    $('input[name="yotm_enable_sizes[]"]').each(function(){

      if (window.YOTM_RECOMMENDED_KEEP.includes(this.value)) {
        this.checked = true;
      }

    });

    activateTab('sizes');

  });

  $('#yotm_recommend_go_prune').on('click', function(){
    activateTab('prune');
  });
})(jQuery);
