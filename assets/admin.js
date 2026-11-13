(function () {
	'use strict';
	const root = document.getElementById('cbd-app');
	if (!root || !window.clientBriefDesk) return;
	const { __, sprintf, _n } = window.wp.i18n;
	const config = window.clientBriefDesk;
	const $ = (id) => document.getElementById('cbd-' + id);
	const panel = $('panel');
	const labels = {
		all: __('All statuses', 'client-brief-desk'), open: __('Open', 'client-brief-desk'),
		submitted: __('Submitted', 'client-brief-desk'), changes_requested: __('Changes requested', 'client-brief-desk'),
		approved: __('Approved', 'client-brief-desk'), archived: __('Archived', 'client-brief-desk')
	};
	const actions = {
		create: __('Brief created', 'client-brief-desk'), save: __('Answers saved', 'client-brief-desk'),
		submit: __('Submitted for review', 'client-brief-desk'), approve: __('Brief approved', 'client-brief-desk'),
		request_changes: __('Changes requested', 'client-brief-desk'), reopen: __('Brief reopened', 'client-brief-desk'),
		archive: __('Brief archived', 'client-brief-desk'), duplicate: __('Brief duplicated', 'client-brief-desk'),
		internal_note: __('Internal note saved', 'client-brief-desk'), delete: __('Brief deleted', 'client-brief-desk')
	};
	let page = 1, pages = 1, listSequence = 0, panelSequence = 0, trigger = null;
	let brief = null, draft = null, creating = false, createDirty = false, busy = false, conflict = false;

	function el(tag, className, text) {
		const node = document.createElement(tag);
		if (className) node.className = className;
		if (text !== undefined && text !== null) node.textContent = String(text);
		return node;
	}
	function button(text, callback, style = '', write = false) {
		const node = el('button', 'cbd-button ' + style, text);
		node.type = 'button';
		if (write) node.dataset.write = 'true';
		node.addEventListener('click', callback);
		return node;
	}
	function field(parent, id, label, type = 'text', value = '', help = '') {
		const wrap = el('div', 'cbd-field');
		const caption = el('label', '', label); caption.htmlFor = 'cbd-' + id;
		const input = el(type === 'textarea' ? 'textarea' : 'input');
		input.id = 'cbd-' + id; input.name = id;
		if (type !== 'textarea') input.type = type;
		else input.rows = 4;
		input.value = value;
		wrap.append(caption, input);
		if (help) {
			const hint = el('p', 'cbd-meta', help); hint.id = input.id + '-help';
			input.setAttribute('aria-describedby', hint.id); wrap.append(hint);
		}
		parent.append(wrap);
		input.addEventListener('input', () => { input.setCustomValidity(''); input.removeAttribute('aria-invalid'); });
		return input;
	}
	function invalid(input, message) {
		input.setCustomValidity(message); input.setAttribute('aria-invalid', 'true'); input.reportValidity(); input.focus();
	}
	function api(path, method = 'GET', data) {
		const separator = path.indexOf('?');
		const route = separator < 0 ? path : path.slice(0, separator);
		const query = separator < 0 ? '' : path.slice(separator + 1);
		const url = new URL(config.root);
		if (url.searchParams.has('rest_route')) url.searchParams.set('rest_route', url.searchParams.get('rest_route') + route);
		else url.pathname += route;
		new URLSearchParams(query).forEach((value, key) => url.searchParams.set(key, value));
		return window.wp.apiFetch({ url: url.href, method, data, headers: { 'X-WP-Nonce': config.nonce }, cache: 'no-store' });
	}
	function errorText(error) {
		if (error?.code === 'rest_cookie_invalid_nonce' || error?.data?.status === 401) {
			return __('Your session expired. Copy any unsaved text before reloading this page and logging in again.', 'client-brief-desk');
		}
		return typeof error?.message === 'string' && !(error instanceof Error) && error.code !== 'fetch_error'
			? sprintf(__('%s Your typed content has been kept. Review it and try again, or load the latest brief.', 'client-brief-desk'), error.message)
			: __('The request could not be confirmed. Your typed content has been kept. Check your connection and load the latest brief before retrying.', 'client-brief-desk');
	}
	function showError(error, target = 'panel-error') {
		$(target).textContent = errorText(error); $(target).hidden = false;
		if (target === 'panel-error' && error?.data?.status === 409 && brief) {
			conflict = true; $('conflict').hidden = false; setBusy(false);
		}
	}
	function clearError() { $('panel-error').hidden = true; $('panel-error').textContent = ''; $('panel-notice').textContent = ''; }
	function status(value) { return el('span', 'cbd-badge cbd-state-' + (Object.hasOwn(labels, value) ? value : 'open'), labels[value] || value); }
	function date(value) {
		if (!value) return __('No due date', 'client-brief-desk');
		const dateOnly = /^\d{4}-\d{2}-\d{2}$/.test(value);
		let parsed = new Date(dateOnly ? value + 'T12:00:00Z' : value.replace(' ', 'T') + (/Z$|[+-]\d{2}:\d{2}$/.test(value) ? '' : 'Z'));
		if (Number.isNaN(parsed.getTime())) return value;
		let zone = dateOnly ? 'UTC' : config.timezone;
		if (!dateOnly && /^[+-]\d{2}:\d{2}$/.test(zone)) {
			const sign = zone[0] === '-' ? -1 : 1;
			parsed = new Date(parsed.getTime() + sign * (Number(zone.slice(1, 3)) * 60 + Number(zone.slice(4))) * 60000);
			zone = 'UTC';
		}
		return new Intl.DateTimeFormat(document.documentElement.lang || undefined, { dateStyle: 'medium', ...(dateOnly ? {} : { timeStyle: 'short' }), timeZone: zone || 'UTC' }).format(parsed);
	}
	function answersDirty() { return brief && draft && brief.questions.some(q => (draft.answers[q.id] || '') !== (brief.answers[q.id] || '')); }
	function dirty() { return creating ? createDirty : !!(brief && draft && (answersDirty() || draft.internalNote !== (brief.internal_note || '') || draft.feedback)); }
	function canAnswer() { return brief && Number(brief.client_id) === Number(config.currentUserId) && ['open', 'changes_requested'].includes(brief.status); }
	function setBusy(value) {
		busy = value;
		$('panel-content').setAttribute('aria-busy', String(value));
		panel.querySelectorAll('fieldset[data-lock]').forEach(node => { node.disabled = value; });
		panel.querySelectorAll('[data-write]').forEach(node => { node.disabled = value || conflict; });
		$('close').disabled = value; $('reload').disabled = value;
	}
	function closePanel() {
		if (busy) return;
		if (dirty() && !window.confirm(__('Discard unsaved text and close this brief? Saved changes will remain.', 'client-brief-desk'))) return;
		panelSequence++; panel.close(); brief = null; draft = null; creating = false; createDirty = false;
		if (trigger?.isConnected) trigger.focus(); else (config.isAdmin ? $('new') : $('search')).focus();
	}
	function openPanel(title, kicker) {
		trigger = document.activeElement;
		clearError(); conflict = false; $('conflict').hidden = true;
		$('panel-title').textContent = title; $('panel-kicker').textContent = kicker;
		$('panel-content').replaceChildren(el('p', 'cbd-empty', __('Loading…', 'client-brief-desk')));
		if (!panel.open) panel.showModal();
		$('panel-title').focus();
	}

	async function loadList() {
		const sequence = ++listSequence;
		$('list-error').hidden = true; $('list').setAttribute('aria-busy', 'true');
		$('prev').disabled = true; $('next').disabled = true;
		try {
			const result = await api('briefs?' + new URLSearchParams({ status: $('status').value, search: $('search').value.trim(), page }));
			if (sequence !== listSequence) return;
			if (page > result.pages) { page = result.pages; return loadList(); }
			pages = result.pages; config.timezone = result.timezone || config.timezone;
			$('total').textContent = sprintf(_n('%d brief', '%d briefs', result.total, 'client-brief-desk'), result.total);
			Array.from($('status').options).forEach(option => { option.textContent = option.value === 'all' ? labels.all : sprintf(__('%1$s (%2$d)', 'client-brief-desk'), labels[option.value], result.counts[option.value] || 0); });
			const list = el('ul', 'cbd-rows');
			result.items.forEach(item => {
				const row = el('li', 'cbd-row');
				const identity = el('div', 'cbd-row-title');
				identity.append(button(item.title, () => openBrief(item.id), 'cbd-title-button'));
				identity.append(el('p', 'cbd-meta', config.isAdmin ? (item.client_name || __('Deleted account', 'client-brief-desk')) : sprintf(__('Brief #%d', 'client-brief-desk'), item.id)));
				const progress = el('p', 'cbd-row-progress');
				progress.append(el('span', 'cbd-meta', __('Answers', 'client-brief-desk')), el('strong', '', sprintf(__('%1$d / %2$d', 'client-brief-desk'), item.answered, item.total_questions)));
				const due = el('p', 'cbd-row-due'); due.append(el('span', 'cbd-meta', __('Due', 'client-brief-desk')), el('span', '', date(item.due_date)));
				row.append(identity, status(item.status), progress, due); list.append(row);
			});
			if (result.items.length) $('list').replaceChildren(list);
			else {
				const empty = el('div', 'cbd-empty');
				empty.append(el('h3', '', __('No briefs here yet', 'client-brief-desk')), el('p', '', config.isAdmin ? __('Create a brief for an existing client, or adjust your filters.', 'client-brief-desk') : __('Briefs assigned to your account will appear here. Try clearing your filters or ask your contact about an expected brief.', 'client-brief-desk')));
				empty.append(button(__('Clear filters', 'client-brief-desk'), () => { $('search').value = ''; $('status').value = 'all'; page = 1; loadList(); }));
				$('list').replaceChildren(empty);
			}
			$('page').textContent = sprintf(__('Page %1$d of %2$d', 'client-brief-desk'), page, pages);
			$('prev').disabled = page <= 1; $('next').disabled = page >= pages;
		} catch (error) {
			if (sequence === listSequence) {
				$('list-error').textContent = __('Briefs could not be refreshed. Check your connection and select Refresh. Any rows below are from the previous load.', 'client-brief-desk');
				$('list-error').hidden = false;
				$('list').querySelector('.cbd-empty')?.remove();
			}
		} finally { if (sequence === listSequence) $('list').setAttribute('aria-busy', 'false'); }
	}

	async function openBrief(id) {
		const sequence = ++panelSequence; creating = false; brief = null; draft = null;
		openPanel(__('Brief details', 'client-brief-desk'), __('Review desk', 'client-brief-desk'));
		try {
			const result = await api('briefs/' + id);
			if (sequence === panelSequence && panel.open) { renderDetail(result); $('panel-title').focus(); }
		} catch (error) {
			if (sequence !== panelSequence) return;
			showError(error); $('panel-content').replaceChildren(button(__('Try again', 'client-brief-desk'), () => openBrief(id)));
		}
	}
	function answerSummary(parent, questions, answers) {
		questions.forEach((question, index) => {
			const item = el('div', 'cbd-answer');
			item.append(el('h4', '', sprintf(__('%1$d. %2$s', 'client-brief-desk'), index + 1, question.label)));
			if (question.help) item.append(el('p', 'cbd-meta', question.help));
			item.append(el('p', 'cbd-answer-text', answers?.[question.id] || __('No answer saved', 'client-brief-desk'))); parent.append(item);
		});
	}
	function renderDetail(record, keepDraft = false, compare = false) {
		const previous = keepDraft ? draft : null;
		const nextDraft = { answers: { ...record.answers }, internalNote: record.internal_note || '', feedback: previous?.feedback || '' };
		if (previous && brief) {
			brief.questions.forEach(q => {
				if ((previous.answers[q.id] || '') !== (brief.answers[q.id] || '')) nextDraft.answers[q.id] = previous.answers[q.id] || '';
			});
			if (previous.internalNote !== (brief.internal_note || '')) nextDraft.internalNote = previous.internalNote;
		}
		brief = record; creating = false;
		draft = nextDraft;
		$('panel-title').textContent = record.title;
		$('panel-kicker').textContent = sprintf(__('Brief #%1$d · Version %2$d', 'client-brief-desk'), record.id, record.version);
		const body = el('fieldset', 'cbd-body'); body.dataset.lock = 'true';
		const legend = el('legend', 'cbd-sr-only', __('Brief details and actions', 'client-brief-desk')); body.append(legend);
		const meta = el('div', 'cbd-detail-meta'); meta.append(status(record.status), el('span', 'cbd-meta', sprintf(__('Due: %s', 'client-brief-desk'), date(record.due_date))));
		if (config.isAdmin) meta.append(el('span', 'cbd-meta', record.client_name || __('Deleted account', 'client-brief-desk')));
		body.append(meta);
		const tools = el('div', 'cbd-actions cbd-tools');
		tools.append(button(__('Export JSON', 'client-brief-desk'), exportBrief), button(__('Load latest', 'client-brief-desk'), reloadLatest)); body.append(tools);
		if (record.client_note) {
			const feedback = el('section', 'cbd-feedback'); feedback.append(el('h3', '', __('Feedback for the client', 'client-brief-desk')), el('p', 'cbd-answer-text', record.client_note)); body.append(feedback);
		}
		if (compare && previous) {
			const comparison = el('details', 'cbd-comparison'); comparison.open = true;
			comparison.append(el('summary', '', __('Latest saved version — compare with your draft below', 'client-brief-desk')));
			answerSummary(comparison, record.questions, record.answers);
			if (config.isAdmin) comparison.append(el('h4', '', __('Latest saved internal note', 'client-brief-desk')), el('p', 'cbd-answer-text', record.internal_note || __('No internal note', 'client-brief-desk')));
			body.append(comparison);
		}
		const answers = el('section', 'cbd-detail-section'); answers.append(el('h3', '', __('Answers', 'client-brief-desk')));
		if (canAnswer()) {
			const progress = el('p', 'cbd-meta'); progress.id = 'cbd-answer-progress'; progress.setAttribute('role', 'status'); answers.append(progress);
			record.questions.forEach((question, index) => {
				const input = field(answers, 'answer-' + question.id, sprintf(__('%1$d. %2$s', 'client-brief-desk'), index + 1, question.label), 'textarea', draft.answers[question.id] || '', [question.required ? __('Required to submit', 'client-brief-desk') : __('Optional', 'client-brief-desk'), question.help].filter(Boolean).join(' · '));
				input.maxLength = 10000;
				input.addEventListener('input', () => { draft.answers[question.id] = input.value; updateProgress(); });
			});
			const buttons = el('div', 'cbd-actions');
			buttons.append(button(__('Save answers', 'client-brief-desk'), () => mutate('save'), '', true), button(__('Submit for review', 'client-brief-desk'), () => confirmAction('submit'), 'cbd-primary', true));
			answers.append(el('p', 'cbd-meta', __('Save incomplete answers whenever you need. Submitting saves your answers and locks them for review.', 'client-brief-desk')), buttons);
		} else {
			answers.append(el('p', 'cbd-meta', config.isAdmin && Number(record.client_id) !== Number(config.currentUserId) ? __('Only the assigned client can answer this brief.', 'client-brief-desk') : __('These answers are read-only in the current status.', 'client-brief-desk')));
			answerSummary(answers, record.questions, record.answers);
			if (previous && answersDirty()) {
				const recovery = el('section', 'cbd-recovery'); recovery.append(el('h3', '', __('Your unsaved answers', 'client-brief-desk')), el('p', 'cbd-meta', __('The latest status does not allow saving. Copy this preserved draft before closing.', 'client-brief-desk')));
				record.questions.forEach(q => { field(recovery, 'recovery-' + q.id, q.label, 'textarea', draft.answers[q.id] || '').readOnly = true; }); answers.append(recovery);
			}
		}
		body.append(answers);
		if (config.isAdmin) {
			const review = el('section', 'cbd-detail-section'); review.append(el('h3', '', __('Staff review', 'client-brief-desk')));
			const reviewable = ['submitted', 'approved'].includes(record.status);
			if (reviewable || draft.feedback) {
				const feedback = field(review, 'feedback', __('Feedback to client', 'client-brief-desk'), 'textarea', draft.feedback, __('Required when requesting changes or reopening. Visible to the client.', 'client-brief-desk'));
				feedback.maxLength = 5000; feedback.addEventListener('input', () => { draft.feedback = feedback.value; });
			}
			const controls = el('div', 'cbd-actions');
			if (record.status === 'submitted') controls.append(button(__('Approve', 'client-brief-desk'), () => confirmAction('approve'), 'cbd-primary', true), button(__('Request changes', 'client-brief-desk'), () => confirmAction('request_changes'), '', true));
			if (record.status === 'approved') controls.append(button(__('Reopen', 'client-brief-desk'), () => confirmAction('reopen'), '', true));
			if (!reviewable) review.append(el('p', 'cbd-meta', record.status === 'archived' ? __('Archived briefs cannot be changed or restored.', 'client-brief-desk') : __('The client can save answers now. Review becomes available after submission.', 'client-brief-desk')));
			review.append(controls);
			const note = field(review, 'internal-note', __('Internal note', 'client-brief-desk'), 'textarea', draft.internalNote, __('For staff only. Never included in the client view or client export.', 'client-brief-desk'));
			note.maxLength = 5000; note.readOnly = record.status === 'archived';
			note.addEventListener('input', () => { draft.internalNote = note.value; });
			if (record.status !== 'archived') review.append(button(__('Save internal note', 'client-brief-desk'), () => mutate('internal_note'), '', true));
			body.append(review);
			const more = el('details', 'cbd-more'); more.append(el('summary', '', __('Manage brief', 'client-brief-desk')));
			const management = el('div', 'cbd-actions'); management.append(button(__('Duplicate', 'client-brief-desk'), () => confirmAction('duplicate'), '', true));
			if (record.status === 'archived') management.append(button(__('Delete archived brief', 'client-brief-desk'), () => confirmAction('delete'), 'cbd-danger', true));
			else management.append(button(__('Archive', 'client-brief-desk'), () => confirmAction('archive'), '', true));
			more.append(management); body.append(more);
		}
		const confirmation = el('section', 'cbd-confirmation'); confirmation.id = 'cbd-confirmation'; confirmation.hidden = true; body.append(confirmation);
		const history = el('section', 'cbd-detail-section'); history.append(el('h3', '', __('Event history', 'client-brief-desk')), el('p', 'cbd-meta', sprintf(__('Most recent 50 events. Times shown in %s.', 'client-brief-desk'), config.timezone)));
		const events = el('ol', 'cbd-history');
		(record.events || []).filter(event => config.isAdmin || event.action !== 'internal_note').forEach(event => {
			const entry = el('li'); entry.append(el('strong', '', actions[event.action] || event.action), el('p', 'cbd-meta', sprintf(__('%1$s · %2$s · Version %3$d', 'client-brief-desk'), event.actor_name, date(event.created_at), event.version)));
			if (event.note) entry.append(el('p', 'cbd-answer-text', event.note));
			if (event.snapshot?.questions) {
				const snapshot = el('details'); snapshot.append(el('summary', '', __('View recorded answers', 'client-brief-desk')));
				if (event.snapshot.title) snapshot.append(el('h4', '', event.snapshot.title));
				answerSummary(snapshot, event.snapshot.questions, event.snapshot.answers); entry.append(snapshot);
			}
			events.append(entry);
		});
		history.append(events.children.length ? events : el('p', 'cbd-meta', __('No events to show.', 'client-brief-desk'))); body.append(history);
		$('panel-content').replaceChildren(body); updateProgress(); setBusy(busy);
	}
	function updateProgress() {
		if (!$('answer-progress')) return;
		const required = brief.questions.filter(q => q.required);
		const complete = required.filter(q => (draft.answers[q.id] || '').trim()).length;
		$('answer-progress').textContent = sprintf(__('%1$d of %2$d required answers complete · %3$s', 'client-brief-desk'), complete, required.length, answersDirty() ? __('Unsaved changes', 'client-brief-desk') : __('Saved', 'client-brief-desk'));
	}
	function validateAnswers(required) {
		let length = 0;
		for (const q of brief.questions) {
			const value = draft.answers[q.id] || ''; length += Array.from(value).length;
			if (required && q.required && !value.trim()) { invalid($('answer-' + q.id), __('Answer this question before submitting.', 'client-brief-desk')); return false; }
		}
		if (length > 50000) { showError({ message: __('Shorten your answers to a total of 50,000 characters or fewer.', 'client-brief-desk') }); return false; }
		return true;
	}
	function confirmAction(action) {
		if (busy || conflict) return;
		if (action === 'submit' && !validateAnswers(true)) return;
		if (['request_changes', 'reopen'].includes(action) && !draft.feedback.trim()) { invalid($('feedback'), __('Explain what the client needs to change.', 'client-brief-desk')); return; }
		const descriptions = {
			submit: __('Save these answers and submit them for staff review? You cannot edit them again unless changes are requested.', 'client-brief-desk'),
			approve: __('Approve this saved version? Its answers will be recorded in the event history and remain read-only until reopened. Feedback typed above is only sent when requesting changes.', 'client-brief-desk'),
			request_changes: __('Return this brief to the client with the feedback above? They will be able to edit and resubmit.', 'client-brief-desk'),
			reopen: __('Reopen this approved brief with the feedback above? The client will be able to change the answers; the earlier approval remains in history.', 'client-brief-desk'),
			archive: __('Archive this brief? It becomes read-only and cannot be restored. Unsaved notes are not included.', 'client-brief-desk'),
			duplicate: __('Create an open copy for the same client with these questions? Answers, notes and due date start empty. Unsaved text in this panel will not be copied.', 'client-brief-desk'),
			delete: __('Permanently delete this archived brief and its event history? This cannot be undone.', 'client-brief-desk')
		};
		const titles = { submit: __('Confirm submission', 'client-brief-desk'), approve: __('Confirm approval', 'client-brief-desk'), request_changes: __('Confirm changes request', 'client-brief-desk'), reopen: __('Confirm reopen', 'client-brief-desk'), archive: __('Confirm archive', 'client-brief-desk'), duplicate: __('Confirm duplicate', 'client-brief-desk'), delete: __('Permanently delete brief', 'client-brief-desk') };
		const target = $('confirmation'); const heading = el('h3', '', titles[action]); heading.tabIndex = -1;
		target.replaceChildren(heading, el('p', '', brief.title), el('p', '', descriptions[action]));
		if (['request_changes', 'reopen'].includes(action)) target.append(el('p', 'cbd-answer-text', draft.feedback));
		const buttons = el('div', 'cbd-actions');
		buttons.append(button(titles[action], () => mutate(action), action === 'delete' ? 'cbd-danger' : 'cbd-primary', true), button(__('Cancel', 'client-brief-desk'), () => { target.hidden = true; $('panel-title').focus(); }));
		target.append(buttons); target.hidden = false; heading.focus();
	}
	async function mutate(action) {
		if (busy || conflict || !brief) return;
		if (['save', 'submit'].includes(action) && !validateAnswers(action === 'submit')) return;
		setBusy(true); clearError();
		try {
			if (action === 'save' || (action === 'submit' && answersDirty())) {
				brief = await api('briefs/' + brief.id, 'PATCH', { version: brief.version, answers: draft.answers });
				draft.answers = { ...brief.answers };
			}
			let result = brief;
			if (action !== 'save') result = await api('briefs/' + brief.id + '/action', 'POST', { version: brief.version, action, ...(['request_changes', 'reopen', 'internal_note'].includes(action) ? { note: action === 'internal_note' ? draft.internalNote : draft.feedback } : {}) });
			if (action === 'delete') {
				brief = null; draft = null; setBusy(false); closePanel();
				$('announcement').textContent = actions.delete;
			} else {
				if (action === 'internal_note') draft.internalNote = result.internal_note || '';
				if (['request_changes', 'reopen'].includes(action)) draft.feedback = '';
				renderDetail(result, action !== 'duplicate');
				$('panel-notice').textContent = actions[action]; $('panel-title').focus();
			}
			loadList();
		} catch (error) { showError(error); }
		finally { setBusy(false); updateProgress(); }
	}
	async function reloadLatest() {
		if (busy || !brief) return;
		const keep = dirty(); setBusy(true); clearError();
		try {
			const latest = await api('briefs/' + brief.id);
			conflict = false; $('conflict').hidden = true; renderDetail(latest, keep, keep);
			$('panel-notice').textContent = keep ? __('Latest version loaded. Your draft is preserved below; compare before saving or reviewing.', 'client-brief-desk') : __('Latest version loaded.', 'client-brief-desk');
			$('panel-title').focus();
		} catch (error) { showError(error); }
		finally { setBusy(false); }
	}
	async function exportBrief() {
		if (busy || !brief) return;
		setBusy(true); clearError();
		try {
			const data = await api('briefs/' + brief.id + '/export');
			const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' }));
			const link = el('a'); link.href = url; link.download = 'client-brief-' + brief.id + '.json';
			panel.append(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
			$('panel-notice').textContent = __('Saved brief exported. Unsaved text is not included.', 'client-brief-desk');
		} catch (error) { showError(error); }
		finally { setBusy(false); }
	}

	function newBrief() {
		const sequence = ++panelSequence; creating = true; createDirty = false; brief = null; draft = null;
		openPanel(__('Create a brief', 'client-brief-desk'), __('A clear starting point', 'client-brief-desk'));
		const form = el('form'); const fields = el('fieldset', 'cbd-body'); fields.dataset.lock = 'true';
		fields.append(el('legend', 'cbd-sr-only', __('New brief details', 'client-brief-desk')), el('p', 'cbd-meta', __('Choose a client and the questions you need answered. Questions and the client cannot be changed after creation.', 'client-brief-desk')));
		const title = field(fields, 'title', __('Brief title', 'client-brief-desk')); title.required = true; title.maxLength = 120;
		const clientSearch = field(fields, 'client-search', __('Find client account', 'client-brief-desk'), 'search', '', __('Enter at least two characters. Select an existing account from the results; account IDs distinguish identical names.', 'client-brief-desk'));
		clientSearch.maxLength = 80; clientSearch.required = true; clientSearch.autocomplete = 'off';
		const clientResults = el('fieldset', 'cbd-clients'); const clientLegend = el('legend', 'cbd-meta', __('Matching client accounts', 'client-brief-desk')); clientResults.append(clientLegend);
		const clientMessage = el('p', 'cbd-meta'); clientMessage.setAttribute('role', 'status'); fields.append(clientMessage, clientResults);
		let selectedClient = null, clientSequence = 0, clientTimer;
		clientSearch.addEventListener('input', () => {
			selectedClient = null; clearTimeout(clientTimer); const request = ++clientSequence;
			clientResults.replaceChildren(clientLegend); clientMessage.textContent = '';
			const term = clientSearch.value.trim(); if (term.length < 2) return;
			clientMessage.textContent = __('Finding accounts…', 'client-brief-desk');
			clientTimer = setTimeout(async () => {
				try {
					const clients = await api('clients?' + new URLSearchParams({ search: term }));
					if (request !== clientSequence || sequence !== panelSequence) return;
					clientMessage.textContent = clients.length ? __('Select the account for this brief. If it is not listed, refine your search.', 'client-brief-desk') : __('No matching accounts. Try another name or create the account in WordPress first.', 'client-brief-desk');
					clients.forEach(client => {
						const label = el('label', 'cbd-client-option'); const radio = el('input'); radio.type = 'radio'; radio.name = 'client_id'; radio.value = String(client.id);
						radio.addEventListener('change', () => { selectedClient = client; clientSearch.setCustomValidity(''); clientSearch.removeAttribute('aria-invalid'); createDirty = true; });
						label.append(radio, el('span', '', sprintf(__('%1$s · Account #%2$d', 'client-brief-desk'), client.name, client.id))); clientResults.append(label);
					});
				} catch (error) { if (request === clientSequence && sequence === panelSequence) clientMessage.textContent = __('Accounts could not be loaded. Check your connection and search again.', 'client-brief-desk'); }
			}, 250);
		});
		const due = field(fields, 'due-date', __('Due date (optional)', 'client-brief-desk'), 'date', '', sprintf(__('Calendar date in %s.', 'client-brief-desk'), config.timezone));
		const presetWrap = el('div', 'cbd-field'); const presetLabel = el('label', '', __('Question preset', 'client-brief-desk')); presetLabel.htmlFor = 'cbd-preset';
		const preset = el('select'); preset.id = 'cbd-preset'; preset.name = 'preset'; preset.append(new Option(__('Custom questions', 'client-brief-desk'), 'custom'));
		presetWrap.append(presetLabel, preset); fields.append(presetWrap);
		const questions = el('div', 'cbd-questions'); fields.append(questions);
		let questionId = 0, presets = {}, previousPreset = 'custom';
		const add = button(__('Add question', 'client-brief-desk'), () => { addQuestion(); createDirty = true; questions.lastElementChild.querySelector('input').focus(); });
		function renumber() {
			Array.from(questions.children).forEach((item, index) => { item.querySelector('legend').textContent = sprintf(__('Question %d', 'client-brief-desk'), index + 1); item.querySelector('button').setAttribute('aria-label', sprintf(__('Remove question %d', 'client-brief-desk'), index + 1)); });
			add.disabled = questions.children.length >= 20;
		}
		function addQuestion(question = { label: '', help: '', required: true }) {
			if (questions.children.length >= 20) return;
			const item = el('fieldset', 'cbd-question'); item.append(el('legend')); const id = ++questionId;
			const label = field(item, 'question-' + id, __('Question text', 'client-brief-desk'), 'text', question.label); label.required = true; label.maxLength = 200; label.dataset.question = 'label';
			const help = field(item, 'help-' + id, __('Guidance (optional)', 'client-brief-desk'), 'textarea', question.help || ''); help.maxLength = 500; help.rows = 2; help.dataset.question = 'help';
			const bottom = el('div', 'cbd-question-bottom'); const requiredLabel = el('label', 'cbd-checkbox'); const required = el('input'); required.type = 'checkbox'; required.checked = !!question.required; required.dataset.question = 'required';
			requiredLabel.append(required, document.createTextNode(__('Required to submit', 'client-brief-desk')));
			bottom.append(requiredLabel, button(__('Remove question', 'client-brief-desk'), () => { item.remove(); createDirty = true; renumber(); add.focus(); }, 'cbd-quiet'));
			item.append(bottom); questions.append(item); renumber();
		}
		addQuestion(); fields.append(add, el('p', 'cbd-meta', __('Use 1–20 questions. Clients can save incomplete answers.', 'client-brief-desk')));
		const presetStatus = el('p', 'cbd-meta', __('Loading presets…', 'client-brief-desk')); presetStatus.setAttribute('role', 'status'); presetWrap.append(presetStatus);
		async function loadPresets() {
			try {
				presets = await api('presets'); if (sequence !== panelSequence) return;
				preset.replaceChildren(new Option(__('Custom questions', 'client-brief-desk'), 'custom'));
				[[ 'service', __('Service brief', 'client-brief-desk') ], [ 'website', __('Website brief', 'client-brief-desk') ], [ 'event', __('Event brief', 'client-brief-desk') ]].forEach(([key, text]) => { if (presets[key]) preset.append(new Option(text, key)); });
				presetStatus.textContent = __('Presets are a starting point. Edit the questions before creating your brief.', 'client-brief-desk');
			} catch (error) {
				if (sequence !== panelSequence) return;
				presetStatus.replaceChildren(document.createTextNode(__('Presets could not load. You can write custom questions or try again.', 'client-brief-desk')), button(__('Retry presets', 'client-brief-desk'), loadPresets));
			}
		}
		preset.addEventListener('change', () => {
			if (preset.value === 'custom') { previousPreset = 'custom'; return; }
			const hasText = Array.from(questions.querySelectorAll('[data-question="label"], [data-question="help"]')).some(input => input.value.trim());
			if (hasText && !window.confirm(__('Replace the current questions with this preset? Any question edits will be discarded.', 'client-brief-desk'))) { preset.value = previousPreset; return; }
			questions.replaceChildren(); presets[preset.value].forEach(addQuestion); previousPreset = preset.value; createDirty = true;
		});
		const submit = el('button', 'cbd-button cbd-primary', __('Create brief', 'client-brief-desk')); submit.type = 'submit'; submit.dataset.write = 'true';
		fields.append(el('p', 'cbd-meta', __('The brief will appear in the client’s portal. Share the portal page with them; no email is sent.', 'client-brief-desk')), submit); form.append(fields);
		form.addEventListener('input', () => { createDirty = true; });
		form.addEventListener('submit', async event => {
			event.preventDefault(); if (busy) return; clearError();
			if (!title.value.trim()) { invalid(title, __('Enter a brief title.', 'client-brief-desk')); return; }
			if (!selectedClient) { invalid(clientSearch, __('Select a client account from the search results.', 'client-brief-desk')); return; }
			if (!questions.children.length) { showError({ message: __('Add at least one question.', 'client-brief-desk') }); add.focus(); return; }
			const list = Array.from(questions.children).map(item => ({ label: item.querySelector('[data-question="label"]').value.trim(), help: item.querySelector('[data-question="help"]').value, required: item.querySelector('[data-question="required"]').checked }));
			const blank = list.findIndex(q => !q.label); if (blank !== -1) { invalid(questions.children[blank].querySelector('[data-question="label"]'), __('Enter question text.', 'client-brief-desk')); return; }
			setBusy(true);
			try {
				const result = await api('briefs', 'POST', { title: title.value.trim(), client_id: selectedClient.id, due_date: due.value, questions: list });
				createDirty = false; renderDetail(result); $('panel-notice').textContent = __('Brief created. It is now available to the assigned client.', 'client-brief-desk'); $('panel-title').focus(); loadList();
			} catch (error) { showError(error); }
			finally { setBusy(false); }
		});
		$('panel-content').replaceChildren(form); loadPresets(); title.focus();
	}

	Object.entries(labels).forEach(([key, text]) => $('status').append(new Option(text, key)));
	$('filters').addEventListener('submit', event => { event.preventDefault(); page = 1; loadList(); });
	$('status').addEventListener('change', () => { page = 1; loadList(); });
	$('refresh').addEventListener('click', loadList);
	$('prev').addEventListener('click', () => { if (page > 1) { page--; loadList(); } });
	$('next').addEventListener('click', () => { if (page < pages) { page++; loadList(); } });
	$('new')?.addEventListener('click', newBrief);
	$('close').addEventListener('click', closePanel);
	$('reload').addEventListener('click', reloadLatest);
	panel.addEventListener('input', () => { if ($('confirmation')) $('confirmation').hidden = true; });
	panel.addEventListener('cancel', event => { event.preventDefault(); closePanel(); });
	window.addEventListener('beforeunload', event => { if (dirty() || busy) { event.preventDefault(); event.returnValue = ''; } });
	loadList();
})();
