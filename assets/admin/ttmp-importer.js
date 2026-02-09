(function ($) {
	'use strict';

	var allPosts = [];
	var isImporting = false;

	function escHtml(str) {
		if (!str) return '';
		return $('<div/>').text(str).html();
	}

	// =========================================================================
	// Fetch Posts
	// =========================================================================

	function fetchAllPosts() {
		var $btn = $('#ttmp-fetch');
		var $progress = $('#ttmp-progress');
		var $progressBar = $progress.find('.ttmp-progress-bar');
		var $progressText = $progress.find('.ttmp-progress-text');

		$btn.prop('disabled', true).text(ttmpImporter.i18n.fetching);
		$progress.show();
		allPosts = [];

		fetchType('photo', 0, function () {
			fetchType('video', 0, function () {
				fetchType('text', 0, function () {
					$btn.prop('disabled', false).text('Fetch Posts from Tumblr');
					$progress.hide();

					// Filter out posts without images or videos
					allPosts = allPosts.filter(function (post) {
						return (
							(post.images && post.images.length > 0) ||
							post.video_url ||
							post.vimeo_id
						);
					});

					// Deduplicate by tumblr_id
					var seen = {};
					allPosts = allPosts.filter(function (post) {
						if (seen[post.tumblr_id]) return false;
						seen[post.tumblr_id] = true;
						return true;
					});

					if (allPosts.length === 0) {
						alert(ttmpImporter.i18n.noPostsFound);
						return;
					}

					renderPostsList();
				});
			});
		});

		function fetchType(type, offset, callback) {
			$progressText.text(
				ttmpImporter.i18n.fetching + ' (' + type + 's, offset: ' + offset + ')'
			);

			$.ajax({
				url: ttmpImporter.ajaxUrl,
				type: 'POST',
				data: {
					action: 'ttmp_fetch_posts',
					nonce: ttmpImporter.nonce,
					offset: offset,
					type: type,
				},
				success: function (response) {
					if (!response.success) {
						alert('Error: ' + (response.data ? response.data.message : 'Unknown error'));
						callback();
						return;
					}

					var data = response.data;
					allPosts = allPosts.concat(data.posts);

					var total = data.totalPosts;
					var fetched = Math.min(offset + 20, total);
					var pct = total > 0 ? Math.round((fetched / total) * 100) : 100;
					$progressBar.css('width', pct + '%');
					$progressText.text(
						type.charAt(0).toUpperCase() + type.slice(1) + 's: ' + fetched + ' / ' + total
					);

					if (data.hasMore) {
						fetchType(type, offset + 20, callback);
					} else {
						callback();
					}
				},
				error: function (xhr, status, error) {
					alert('Request failed: ' + error);
					callback();
				},
			});
		}
	}

	// =========================================================================
	// Render Posts List
	// =========================================================================

	function renderPostsList() {
		var $results = $('#ttmp-results');
		var $list = $('#ttmp-posts-list');

		$results.show();
		$list.empty();

		$.each(allPosts, function (index, post) {
			var thumbHtml;
			if (post.thumbnail) {
				thumbHtml = '<img class="ttmp-post-thumb" src="' + escHtml(post.thumbnail) + '" alt="" loading="lazy" />';
			} else {
				thumbHtml = '<div class="ttmp-post-thumb-placeholder">' + escHtml(post.type) + '</div>';
			}

			var tagsHtml = '';
			if (post.tags && post.tags.length) {
				tagsHtml = '<div class="ttmp-post-tags">';
				$.each(post.tags, function (i, tag) {
					tagsHtml += '<span class="ttmp-tag">' + escHtml(tag) + '</span>';
				});
				tagsHtml += '</div>';
			}

			var title = post.title || '(No title \u2014 will be auto-generated)';
			var dateStr = new Date(post.timestamp * 1000).toLocaleDateString(undefined, {
				year: 'numeric', month: 'short', day: 'numeric',
			});

			var statusHtml = '';
			if (post.already_imported) {
				statusHtml = '<span class="ttmp-post-status status-already">Already imported</span>';
			}

			var checked = post.already_imported ? '' : 'checked';
			var itemClass = post.already_imported ? 'ttmp-post-item imported' : 'ttmp-post-item';

			var typeLabel = post.type === 'video' ? 'Video' : 'Photo';
			if (post.vimeo_id) {
				typeLabel += ' (Vimeo: ' + escHtml(post.vimeo_id) + ')';
			}

			var html =
				'<div class="' + itemClass + '" data-index="' + index + '">' +
				'<input type="checkbox" class="ttmp-post-checkbox" data-index="' + index + '" ' + checked + ' />' +
				thumbHtml +
				'<div class="ttmp-post-info">' +
				'<div class="ttmp-post-title">' + escHtml(title) + '</div>' +
				'<div class="ttmp-post-meta">' + escHtml(typeLabel) + ' &mdash; ' + escHtml(dateStr) + '</div>' +
				tagsHtml +
				'</div>' +
				statusHtml +
				'</div>';

			$list.append(html);
		});

		updateCount();
	}

	function updateCount() {
		var total = allPosts.length;
		var checked = $('#ttmp-posts-list .ttmp-post-checkbox:checked').length;
		$('.ttmp-count').text(checked + ' of ' + total + ' selected');
	}

	// =========================================================================
	// Import
	// =========================================================================

	function importSelected() {
		if (isImporting) return;

		var $checked = $('#ttmp-posts-list .ttmp-post-checkbox:checked');
		if ($checked.length === 0) {
			alert('No posts selected.');
			return;
		}

		if (!confirm(ttmpImporter.i18n.confirmImport)) return;

		isImporting = true;
		$('#ttmp-import-selected').prop('disabled', true);
		$('#ttmp-fetch').prop('disabled', true);
		switchTab('log');

		var queue = [];
		$checked.each(function () {
			queue.push(parseInt($(this).data('index'), 10));
		});

		var $progress = $('#ttmp-progress');
		var $progressBar = $progress.find('.ttmp-progress-bar');
		var $progressText = $progress.find('.ttmp-progress-text');
		$progress.show();

		var total = queue.length;
		var done = 0;

		function importNext() {
			if (queue.length === 0) {
				$progressBar.css('width', '100%');
				$progressText.text(ttmpImporter.i18n.complete + ' (' + done + '/' + total + ')');
				isImporting = false;
				$('#ttmp-import-selected').prop('disabled', false);
				$('#ttmp-fetch').prop('disabled', false);
				return;
			}

			var idx = queue.shift();
			var post = allPosts[idx];
			var $item = $('#ttmp-posts-list .ttmp-post-item[data-index="' + idx + '"]');

			$item.find('.ttmp-post-status').remove();
			$item.append('<span class="ttmp-post-status status-importing">' + ttmpImporter.i18n.importing + '</span>');

			done++;
			var pct = Math.round((done / total) * 100);
			$progressBar.css('width', pct + '%');
			$progressText.text(ttmpImporter.i18n.importing + ' ' + done + ' / ' + total);

			var useAI = $('#ttmp-use-ai').is(':checked') ? '1' : '0';
			var assignCategories = $('#ttmp-assign-categories').is(':checked') ? '1' : '0';

			$.ajax({
				url: ttmpImporter.ajaxUrl,
				type: 'POST',
				data: {
					action: 'ttmp_import_post',
					nonce: ttmpImporter.nonce,
					post_data: post,
					use_ai: useAI,
					assign_categories: assignCategories,
				},
				success: function (response) {
					console.log('[TTMP] Import response:', response.data);
					$item.find('.ttmp-post-status').remove();

					if (response.success) {
						var status = response.data.status;
						if (status === 'imported') {
							$item.addClass('imported');
							$item.append('<span class="ttmp-post-status status-imported">' + ttmpImporter.i18n.imported + '</span>');

							// Update the title in the post list with the final title used
							if (response.data.title) {
								var titleHtml = escHtml(response.data.title);
								if (response.data.ai_source) {
									titleHtml += ' <span class="ttmp-ai-source">(' + escHtml(response.data.ai_source) + ')</span>';
								}
								if (response.data.ai_errors && response.data.ai_errors.length) {
									titleHtml += '<br><small style="color:#b32d2e;">⚠ ' + escHtml(response.data.ai_errors.join(' → ')) + '</small>';
								}
								$item.find('.ttmp-post-title').html(titleHtml);
							}

							var msg = response.data.message;
							if (response.data.ai_source) {
								msg += ' [AI: ' + response.data.ai_source + ']';
							}
							if (response.data.ai_errors && response.data.ai_errors.length) {
								msg += ' ⚠ Vision skipped: ' + response.data.ai_errors[0];
							}
							addLogEntry('success', msg, response.data.edit_url);

							// Log image errors
							if (response.data.image_errors && response.data.image_errors.length) {
								$.each(response.data.image_errors, function (i, err) {
									addLogEntry('error', 'Image error: ' + err);
								});
							}
							// Log AI errors
							if (response.data.ai_errors && response.data.ai_errors.length) {
								$.each(response.data.ai_errors, function (i, err) {
									addLogEntry('info', 'AI fallback: ' + err);
								});
							}
						} else if (status === 'skipped') {
							$item.append('<span class="ttmp-post-status status-skipped">' + ttmpImporter.i18n.skipped + '</span>');
							addLogEntry('skip', response.data.message);
						}
					} else {
						$item.addClass('import-failed');
						$item.append('<span class="ttmp-post-status status-failed">' + ttmpImporter.i18n.failed + '</span>');
						addLogEntry('error', post.title + ': ' + (response.data ? response.data.message : 'Unknown error'));
					}

					$item.find('.ttmp-post-checkbox').prop('checked', false);
					setTimeout(importNext, 2000);
				},
				error: function (xhr, status, error) {
					$item.find('.ttmp-post-status').remove();
					$item.addClass('import-failed');
					$item.append('<span class="ttmp-post-status status-failed">' + ttmpImporter.i18n.failed + '</span>');
					addLogEntry('error', (post.title || 'Post') + ': Request failed - ' + error);
					$item.find('.ttmp-post-checkbox').prop('checked', false);
					setTimeout(importNext, 2000);
				},
			});
		}

		importNext();
	}

	var logCount = 0;

	function addLogEntry(type, message, editUrl) {
		var html = '<div class="ttmp-log-entry log-' + type + '">' + escHtml(message);
		if (editUrl) {
			html += ' <a href="' + escHtml(editUrl) + '" target="_blank">Edit &rarr;</a>';
		}
		html += '</div>';
		$('#ttmp-log-entries').prepend(html);

		logCount++;
		$('#ttmp-log-count').text(logCount).show();
	}

	function switchTab(tabName) {
		$('.ttmp-tab').removeClass('active');
		$('.ttmp-tab[data-tab="' + tabName + '"]').addClass('active');
		$('.ttmp-tab-content').hide();
		$('#ttmp-tab-' + tabName).show();
	}

	// =========================================================================
	// Test API Keys
	// =========================================================================

	function testApiKey($btn) {
		var service = $btn.data('service');
		var $result = $('.ttmp-test-result[data-service="' + service + '"]');

		$btn.prop('disabled', true);
		$result.text('Testing...').removeClass('success error');

		var data = {
			action: 'ttmp_test_api',
			nonce: ttmpImporter.nonce,
			service: service,
		};

		if (service === 'gemini') {
			data.key = $('#ttmp_gemini_key').val();
		} else if (service === 'cloudflare') {
			data.account_id = $('#ttmp_cloudflare_account_id').val();
			data.token = $('#ttmp_cloudflare_api_token').val();
		} else if (service === 'openai') {
			data.key = $('#ttmp_openai_key').val();
		}

		$.ajax({
			url: ttmpImporter.ajaxUrl,
			type: 'POST',
			data: data,
			success: function (response) {
				$btn.prop('disabled', false);
				if (response.success) {
					$result.text(response.data.message).addClass('success');
				} else {
					$result.text(response.data.message).addClass('error');
				}
			},
			error: function () {
				$btn.prop('disabled', false);
				$result.text('Request failed.').addClass('error');
			},
		});
	}

	// =========================================================================
	// Service Order (drag + arrows)
	// =========================================================================

	var serviceNames = {
		gemini: 'Google Gemini',
		cloudflare: 'Cloudflare Workers AI',
		openai: 'OpenAI',
		chatgpt_text: 'ChatGPT (text)',
		gemini_text: 'Gemini (text)',
	};

	function updateChainPreview() {
		var chainParts = [];
		$('#ttmp-sortable-services .ttmp-ai-service-card').each(function () {
			var svc = $(this).data('service');
			if (serviceNames[svc]) chainParts.push(serviceNames[svc]);
		});
		$('#ttmp-sortable-text-services .ttmp-text-service-card').each(function () {
			var svc = $(this).data('service');
			if (serviceNames[svc]) chainParts.push(serviceNames[svc]);
		});
		chainParts.push('Tag fallback');
		$('#ttmp-chain-display').text(chainParts.join(' → '));
	}

	function updateServiceOrder() {
		var order = [];
		$('#ttmp-sortable-services .ttmp-ai-service-card').each(function (i) {
			order.push($(this).data('service'));
			$(this).find('.ttmp-service-priority').text(i + 1);
		});
		$('#ttmp_ai_service_order').val(order.join(','));
		updateChainPreview();
	}

	function updateTextOrder() {
		var order = [];
		$('#ttmp-sortable-text-services .ttmp-text-service-card').each(function (i) {
			order.push($(this).data('service'));
			$(this).find('.ttmp-text-priority').text(i + 1);
		});
		$('#ttmp_ai_text_order').val(order.join(','));
		updateChainPreview();
	}

	function initSortable() {
		if ($.fn.sortable) {
			if ($('#ttmp-sortable-services').length) {
				$('#ttmp-sortable-services').sortable({
					handle: '.ttmp-service-drag',
					axis: 'y',
					containment: 'parent',
					tolerance: 'pointer',
					update: function () {
						updateServiceOrder();
					},
				});
			}
			if ($('#ttmp-sortable-text-services').length) {
				$('#ttmp-sortable-text-services').sortable({
					handle: '.ttmp-service-drag',
					axis: 'y',
					containment: 'parent',
					tolerance: 'pointer',
					update: function () {
						updateTextOrder();
					},
				});
			}
		}
	}

	// =========================================================================
	// Event Bindings
	// =========================================================================

	$(document).ready(function () {
		// Fetch
		$('#ttmp-fetch').on('click', fetchAllPosts);

		// Select / Deselect
		$('#ttmp-select-all').on('click', function () {
			$('#ttmp-posts-list .ttmp-post-checkbox').prop('checked', true);
			updateCount();
		});
		$('#ttmp-deselect-all').on('click', function () {
			$('#ttmp-posts-list .ttmp-post-checkbox').prop('checked', false);
			updateCount();
		});
		$(document).on('change', '.ttmp-post-checkbox', updateCount);

		// Import
		$('#ttmp-import-selected').on('click', importSelected);

		// Test API buttons
		$(document).on('click', '.ttmp-test-api', function () {
			testApiKey($(this));
		});

		// Tab switching
		$(document).on('click', '.ttmp-tab', function (e) {
			e.preventDefault();
			switchTab($(this).data('tab'));
		});

		// Toggle AI services visibility
		$('#ttmp_use_ai').on('change', function () {
			$('#ttmp-ai-services').toggle(this.checked);
		});

		// Toggle category mode visibility
		$('#ttmp_ai_categories').on('change', function () {
			$('#ttmp-category-mode-row').toggle(this.checked);
		});

		// Sortable service cards
		initSortable();

		// Arrow buttons
		$(document).on('click', '.ttmp-move-up', function (e) {
			e.preventDefault();
			var $card = $(this).closest('.ttmp-ai-service-card');
			var $prev = $card.prev('.ttmp-ai-service-card');
			if ($prev.length) {
				$card.insertBefore($prev);
				updateServiceOrder();
			}
		});
		$(document).on('click', '.ttmp-move-down', function (e) {
			e.preventDefault();
			var $card = $(this).closest('.ttmp-ai-service-card');
			var $next = $card.next('.ttmp-ai-service-card');
			if ($next.length) {
				$card.insertAfter($next);
				updateServiceOrder();
			}
		});

		// Text-only service arrow buttons
		$(document).on('click', '.ttmp-text-move-up', function (e) {
			e.preventDefault();
			var $card = $(this).closest('.ttmp-text-service-card');
			var $prev = $card.prev('.ttmp-text-service-card');
			if ($prev.length) {
				$card.insertBefore($prev);
				updateTextOrder();
			}
		});
		$(document).on('click', '.ttmp-text-move-down', function (e) {
			e.preventDefault();
			var $card = $(this).closest('.ttmp-text-service-card');
			var $next = $card.next('.ttmp-text-service-card');
			if ($next.length) {
				$card.insertAfter($next);
				updateTextOrder();
			}
		});
	});
})(jQuery);
