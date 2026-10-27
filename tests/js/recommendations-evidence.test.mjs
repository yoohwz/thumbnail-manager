import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../js/admin-recommendations.js', import.meta.url), 'utf8');
function reportBuilder() {
  const core = {
    escapeHtml: (value) => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;'),
    formatBytes: (value) => String(value || 0) + ' B',
    formatTemplate: (value, replacements) => Object.entries(replacements).reduce((text, [key, replacement]) => text.replaceAll(key, replacement), value),
    t: (key, fallback) => fallback
  };
  const window = {YOTMAdmin: core};
  const context = vm.createContext({window, jQuery: () => ({})});
  // Stop before event wiring; exercise the same public pure builder used by saved reports.
  vm.runInContext(source.replace('  function renderRecommendationResult(result) {', '  return;\n  function renderRecommendationResult(result) {'), context);
  return window.YOTMRecommendationReport;
}

test('saved recommendation report separates hints, reference bytes and unique checked files', () => {
  const build = reportBuilder();
  const html = build({
    statistics: {schema_version: 1, complete: true, attachments_scanned: 3, metadata_entries: 6, checked_files: 1, checked_bytes: 7, missing_files: 1, unreadable_files: 1, unverified_entries: 1},
    items: [{name: 'custom', generated_count: 6, bytes: 21, evidence: [{description: 'Policy protection'}], content_samples: [{post_id: 2, title: '<unsafe>', url: 'https://example.test/wp-admin/post.php?post=2&action=edit', kinds: ['class_size_hint', 'json_size_hint']}]}]
  });
  assert.match(html, /Attachments scanned: 3\. Metadata entries: 6\. Unique checked files: 1 \(7 B\)/);
  assert.match(html, /Missing files: 1\. Unreadable files: 1\. Unverified metadata entries: 1/);
  assert.match(html, /not a live snapshot/);
  assert.match(html, /Per-size bytes count metadata references and may overlap/);
  assert.match(html, /Size class hint, Size setting hint/);
  assert.match(html, /&lt;unsafe&gt;/);
  assert.match(html, /post=2&amp;action=edit/);
  assert.match(html, /<td>6<\/td><td>21 B<\/td>/);
  assert.doesNotMatch(html, /Apply safe recommendations/);
});

test('evidence report rejects executable links, limits samples, and discloses legacy coverage', () => {
  const build = reportBuilder();
  const samples = [{post_id: 1, title: 'malicious', url: 'javascript:alert(1)'}];
  for (let index = 2; index <= 9; ++index) {
    samples.push({post_id: index, title: '#' + index, url: 'https://example.test/' + index});
  }
  const html = build({items: [{name: 'custom', content_samples: samples}]});
  assert.doesNotMatch(html, /javascript:|malicious|example\.test\/6/);
  assert.equal((html.match(/<a href=/g) || []).length, 4);
  assert.match(html, /Unique file statistics are unavailable/);
  assert.match(html, /legacy footprint totals may count shared references more than once/);
  assert.match(html, /Dynamic theme, plugin, custom-field and external usage is not observed/);
});
