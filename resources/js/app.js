import './bootstrap';

const states = [
    'RECEIVED',
    'VALIDATED',
    'SUBMITTED',
    'PENDING_CONFIRMATION',
    'ISSUED',
    'FAILED',
    'NEEDS_RECONCILIATION',
];

const elements = {
    rows: document.querySelector('#requestRows'),
    total: document.querySelector('#totalCount'),
    issued: document.querySelector('#issuedCount'),
    pending: document.querySelector('#pendingCount'),
    failed: document.querySelector('#failedCount'),
    tableCount: document.querySelector('#tableCount'),
    pageSummary: document.querySelector('#paginationSummary'),
    pageNumber: document.querySelector('#pageNumber'),
    previous: document.querySelector('#previousPage'),
    next: document.querySelector('#nextPage'),
    status: document.querySelector('#statusFilter'),
    search: document.querySelector('#searchInput'),
    refresh: document.querySelector('#refreshButton'),
    createButton: document.querySelector('#openCreateButton'),
    modal: document.querySelector('#createModal'),
    form: document.querySelector('#createForm'),
    formError: document.querySelector('#formError'),
    formSuccess: document.querySelector('#formSuccess'),
    createSubmit: document.querySelector('#createSubmit'),
    detailBackdrop: document.querySelector('#detailBackdrop'),
    detailContent: document.querySelector('#detailContent'),
    detailTitle: document.querySelector('#detailTitle'),
    detailId: document.querySelector('#detailRequestId'),
    toast: document.querySelector('#toastRegion'),
    connection: document.querySelector('#apiConnection'),
};

let currentPage = 1;
let lastPage = 1;
let currentRows = [];
let loading = false;

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    })[character]);
}

async function apiRequest(url, options = {}) {
    const response = await fetch(url, {
        ...options,
        headers: {
            Accept: 'application/json',
            ...(options.body ? { 'Content-Type': 'application/json' } : {}),
            ...options.headers,
        },
    });

    let body;
    try {
        body = await response.json();
    } catch {
        throw new Error(`The server returned an unreadable response (${response.status}).`);
    }

    if (!response.ok) {
        const validationMessages = body.errors
            ? Object.values(body.errors).flat().join(' ')
            : '';
        const error = new Error(validationMessages || body.message || `Request failed (${response.status}).`);
        error.validationErrors = body.errors || null;
        throw error;
    }

    return { ...body, _httpStatus: response.status };
}

function formatCurrency(amount) {
    const value = Number(amount);
    if (!Number.isFinite(value)) return '—';

    return new Intl.NumberFormat('en-IN', {
        style: 'currency',
        currency: 'INR',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value);
}

function formatDate(value, options = { day: '2-digit', month: 'short', year: 'numeric' }) {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat('en-IN', options).format(date);
}

function statusClass(state) {
    return `status-${String(state || '').toLowerCase().replaceAll('_', '-')}`;
}

function statusLabel(state) {
    return String(state || 'UNKNOWN').replaceAll('_', ' ');
}

function initials(name) {
    return String(name || 'NA')
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

function toast(message, isError = false) {
    const notice = document.createElement('div');
    notice.className = `toast${isError ? ' error' : ''}`;
    notice.textContent = message;
    elements.toast.append(notice);
    window.setTimeout(() => notice.remove(), 4200);
}

function showTableMessage(title, message, isError = false) {
    elements.rows.innerHTML = `
        <tr><td colspan="7">
            <div class="empty-state">
                <span class="empty-symbol">${isError ? '!' : '▤'}</span>
                <strong>${escapeHtml(title)}</strong>
                <span>${escapeHtml(message)}</span>
            </div>
        </td></tr>`;
}

async function fetchCount(status = '') {
    const query = new URLSearchParams({ per_page: '1' });
    if (status) query.set('status', status);
    const result = await apiRequest(`/api/policy-requests?${query}`);
    elements.connection.classList.remove('offline');
    elements.connection.innerHTML = '<span></span> API connected';
    elements.connection.title = 'Connected to the PolicyFlow API';
    return result.meta?.total ?? result.data?.length ?? 0;
}

async function loadSummary() {
    const [total, issued, pending, failed] = await Promise.all([
        fetchCount(),
        fetchCount('ISSUED'),
        fetchCount('PENDING_CONFIRMATION'),
        fetchCount('FAILED'),
    ]);
    elements.total.textContent = total.toLocaleString('en-IN');
    elements.issued.textContent = issued.toLocaleString('en-IN');
    elements.pending.textContent = pending.toLocaleString('en-IN');
    elements.failed.textContent = failed.toLocaleString('en-IN');
}

function renderRows(requests) {
    currentRows = requests;
    applySearch();
}

function applySearch() {
    const searchTerm = elements.search.value.trim().toLocaleLowerCase();
    const filtered = currentRows.filter((request) => {
        const searchText = [
            request.customer_name,
            request.client_request_id,
            request.agent_id,
            request.destination,
            request.plan_code,
        ].join(' ').toLocaleLowerCase();

        return searchText.includes(searchTerm);
    });

    elements.tableCount.textContent = searchTerm ? `${filtered.length} / ${elements.pageSummary.dataset.total || 0}` : (elements.pageSummary.dataset.total || '0');

    if (!filtered.length) {
        showTableMessage(
            searchTerm ? 'No matches on this page' : 'No policy requests yet',
            searchTerm ? 'Try another name or request ID, or move to a different page.' : 'Create your first policy request to start tracking issuance.'
        );
        return;
    }

    elements.rows.innerHTML = filtered.map((request) => {
        const eligible = ['RECEIVED', 'FAILED'].includes(request.state);
        const destination = request.destination === 'INTERNATIONAL' ? '◎' : '⌖';

        return `
            <tr data-request-id="${escapeHtml(request.id)}">
                <td>
                    <div class="customer-cell">
                        <span class="customer-avatar">${escapeHtml(initials(request.customer_name))}</span>
                        <span class="customer-meta">
                            <strong>${escapeHtml(request.customer_name)}</strong>
                            <span>${escapeHtml(request.client_request_id)}</span>
                        </span>
                    </div>
                </td>
                <td><span class="destination-cell"><span class="destination-icon">${destination}</span>${escapeHtml(request.destination)}</span></td>
                <td><span class="plan-tag">${escapeHtml(request.plan_code)}</span></td>
                <td class="premium-cell">${escapeHtml(formatCurrency(request.premium))}</td>
                <td><span class="status-badge ${statusClass(request.state)}">${escapeHtml(statusLabel(request.state))}</span></td>
                <td class="date-cell">${escapeHtml(formatDate(request.created_at))}</td>
                <td><div class="row-actions">
                    <button class="text-action" type="button" data-action="details" data-id="${escapeHtml(request.id)}">Details</button>
                    ${eligible ? `<button class="text-action submit-action" type="button" data-action="submit" data-id="${escapeHtml(request.id)}">Submit</button>` : ''}
                </div></td>
            </tr>`;
    }).join('');
}

async function loadRequests({ page = currentPage, quiet = false } = {}) {
    if (loading) return;
    loading = true;
    currentPage = page;
    elements.refresh.disabled = true;
    elements.refresh.innerHTML = '<span class="spinner"></span> Loading';

    if (!quiet) {
        elements.rows.innerHTML = '<tr><td colspan="7"><div class="table-loading"><span class="spinner"></span> Loading policy requests…</div></td></tr>';
    }

    try {
        const query = new URLSearchParams({ page: String(page), per_page: '10' });
        if (elements.status.value) query.set('status', elements.status.value);
        const result = await apiRequest(`/api/policy-requests?${query}`);
        const meta = result.meta || {};
        currentPage = meta.current_page || 1;
        lastPage = meta.last_page || 1;
        elements.pageSummary.dataset.total = String(meta.total ?? result.data.length);
        elements.pageSummary.textContent = meta.total
            ? `Showing ${(currentPage - 1) * (meta.per_page || 10) + 1}–${Math.min(currentPage * (meta.per_page || 10), meta.total)} of ${meta.total} requests`
            : 'No requests found';
        elements.pageNumber.textContent = `${currentPage} / ${lastPage}`;
        elements.previous.disabled = currentPage <= 1;
        elements.next.disabled = currentPage >= lastPage;
        renderRows(result.data || []);
        await loadSummary();
    } catch (error) {
        elements.connection.classList.add('offline');
        elements.connection.innerHTML = '<span></span> API unavailable';
        elements.connection.title = error.message;
        showTableMessage('Could not load requests', error.message, true);
        elements.pageSummary.textContent = 'Unable to load requests';
        toast(error.message, true);
    } finally {
        loading = false;
        elements.refresh.disabled = false;
        elements.refresh.innerHTML = '<span class="refresh-icon">↻</span> Refresh';
    }
}

function openCreateModal() {
    elements.form.reset();
    clearFormErrors();
    elements.formError.hidden = true;
    elements.formSuccess.hidden = true;
    elements.modal.hidden = false;
    document.body.style.overflow = 'hidden';
    elements.form.querySelector('[name="client_request_id"]').focus();
}

function closeCreateModal() {
    elements.modal.hidden = true;
    document.body.style.overflow = '';
}

function clearFormErrors() {
    elements.form.querySelectorAll('[data-error-for]').forEach((error) => {
        error.textContent = '';
        error.closest('.form-field')?.classList.remove('has-error');
    });
}

function showValidationErrors(errors) {
    clearFormErrors();
    let firstInvalid = null;
    Object.entries(errors || {}).forEach(([field, messages]) => {
        const target = elements.form.querySelector(`[data-error-for="${CSS.escape(field)}"]`);
        if (!target) return;
        target.textContent = Array.isArray(messages) ? messages.join(' ') : String(messages);
        target.closest('.form-field')?.classList.add('has-error');
        firstInvalid ||= target.closest('.form-field')?.querySelector('input, select');
    });
    firstInvalid?.focus();
}

async function createRequest(event) {
    event.preventDefault();
    clearFormErrors();
    elements.formError.hidden = true;
    elements.formSuccess.hidden = true;

    const formData = new FormData(elements.form);
    const payload = Object.fromEntries(formData.entries());
    payload.sum_insured = Number(payload.sum_insured);
    elements.createSubmit.disabled = true;
    elements.createSubmit.querySelector('.button-label').textContent = 'Creating…';
    elements.createSubmit.querySelector('.button-spinner').hidden = false;

    try {
        const result = await apiRequest('/api/policy-requests', {
            method: 'POST',
            body: JSON.stringify(payload),
        });
        const message = result._httpStatus === 200
            ? 'Existing request returned'
            : 'Policy request created';
        elements.formSuccess.textContent = `${message}: ${result.data?.client_request_id || ''}. Premium ${formatCurrency(result.data?.premium)}.`;
        elements.formSuccess.hidden = false;
        toast(result._httpStatus === 200 ? 'Existing request returned.' : 'Policy request created.');
        await loadRequests({ page: 1, quiet: false });
        window.setTimeout(closeCreateModal, 1000);
    } catch (error) {
        if (error.validationErrors) {
            showValidationErrors(error.validationErrors);
        }
        elements.formError.textContent = error.message;
        elements.formError.hidden = false;
    } finally {
        elements.createSubmit.disabled = false;
        elements.createSubmit.querySelector('.button-label').textContent = 'Calculate & create';
        elements.createSubmit.querySelector('.button-spinner').hidden = true;
    }
}

function showDetailsLoading(id) {
    elements.detailBackdrop.hidden = false;
    document.body.style.overflow = 'hidden';
    elements.detailTitle.textContent = 'Policy request';
    elements.detailId.textContent = `Request #${id}`;
    elements.detailContent.innerHTML = '<div class="table-loading"><span class="spinner"></span> Loading request details…</div>';
}

function renderDetails(request) {
    elements.detailTitle.textContent = request.customer_name || 'Policy request';
    elements.detailId.textContent = `${request.client_request_id} · ${request.uuid}`;
    const breakdown = request.pricing_breakdown || {};
    const trail = request.audit_trail || [];
    const eligible = ['RECEIVED', 'FAILED'].includes(request.state);
    const multiplierRows = [
        ['Base daily rate', `${formatCurrency(breakdown.base_daily_rate)}`],
        ['Inclusive travel days', breakdown.travel_days],
        ['Age on trip start', `${breakdown.age_at_trip_start} years · ${breakdown.age_multiplier}×`],
        ['Destination multiplier', `${breakdown.destination_multiplier}×`],
        ['Coverage multiplier', `${breakdown.coverage_multiplier}×`],
    ];

    elements.detailContent.innerHTML = `
        <div class="detail-section">
            <div class="premium-highlight"><span>Calculated premium</span><strong>${escapeHtml(formatCurrency(request.premium))}</strong></div>
        </div>
        <div class="detail-section">
            <div class="detail-section-title">APPLICANT &amp; TRIP</div>
            <div class="detail-grid">
                <div class="detail-item"><label>Agent ID</label><strong>${escapeHtml(request.agent_id)}</strong></div>
                <div class="detail-item"><label>Current status</label><strong><span class="status-badge ${statusClass(request.state)}">${escapeHtml(statusLabel(request.state))}</span></strong></div>
                <div class="detail-item"><label>Date of birth</label><strong>${escapeHtml(formatDate(request.date_of_birth))}</strong></div>
                <div class="detail-item"><label>Destination</label><strong>${escapeHtml(request.destination)}</strong></div>
                <div class="detail-item"><label>Trip dates</label><strong>${escapeHtml(formatDate(request.trip_start))} – ${escapeHtml(formatDate(request.trip_end))}</strong></div>
                <div class="detail-item"><label>Plan / coverage</label><strong>${escapeHtml(request.plan_code)} · ${escapeHtml(formatCurrency(request.sum_insured))}</strong></div>
                <div class="detail-item"><label>Provider reference</label><strong>${escapeHtml(request.provider_reference || 'Not submitted')}</strong></div>
                <div class="detail-item"><label>Policy number</label><strong>${escapeHtml(request.policy_number || 'Not issued')}</strong></div>
            </div>
        </div>
        <div class="detail-section">
            <div class="detail-section-title">PREMIUM BREAKDOWN</div>
            <div class="detail-grid">
                ${multiplierRows.map(([label, value]) => `<div class="detail-item"><label>${escapeHtml(label)}</label><strong>${escapeHtml(value ?? '—')}</strong></div>`).join('')}
            </div>
        </div>
        <div class="detail-section">
            <div class="detail-section-title">STATE HISTORY</div>
            ${trail.length ? `<div class="timeline">${trail.map((entry) => `
                <div class="timeline-entry">
                    <span class="timeline-dot"></span>
                    <div><strong>${escapeHtml(statusLabel(entry.to_state))}</strong>
                    <span>${escapeHtml(entry.reason || entry.source || 'State updated')}</span>
                    <small>${escapeHtml(formatDate(entry.created_at, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }))}</small></div>
                </div>`).join('')}</div>` : '<div class="empty-state">No state history available.</div>'}
        </div>
        ${eligible ? `<button type="button" class="button button-primary detail-action" data-action="submit" data-id="${escapeHtml(request.id)}">Submit for issuance</button>` : ''}`;
}

async function openDetails(id) {
    showDetailsLoading(id);
    try {
        const result = await apiRequest(`/api/policy-requests/${encodeURIComponent(id)}`);
        renderDetails(result.data);
    } catch (error) {
        elements.detailContent.innerHTML = `<div class="notice notice-error" role="alert">${escapeHtml(error.message)}</div>`;
    }
}

function closeDetails() {
    elements.detailBackdrop.hidden = true;
    document.body.style.overflow = '';
}

async function submitForIssuance(id, button) {
    if (button) {
        button.disabled = true;
        button.dataset.originalText = button.textContent;
        button.innerHTML = '<span class="spinner"></span>';
    }

    try {
        const result = await apiRequest(`/api/policy-requests/${encodeURIComponent(id)}/submit`, {
            method: 'POST',
            body: JSON.stringify({}),
        });
        toast(`Request ${result.data?.client_request_id || id}: ${statusLabel(result.data?.state)}.`);
        if (!elements.detailBackdrop.hidden) await openDetails(id);
        await loadRequests({ quiet: true });
    } catch (error) {
        toast(error.message, true);
        if (button) {
            button.disabled = false;
            button.textContent = button.dataset.originalText || 'Submit';
        }
    }
}

elements.refresh.addEventListener('click', () => loadRequests({ page: 1 }));
elements.previous.addEventListener('click', () => loadRequests({ page: Math.max(1, currentPage - 1) }));
elements.next.addEventListener('click', () => loadRequests({ page: Math.min(lastPage, currentPage + 1) }));
elements.status.addEventListener('change', () => loadRequests({ page: 1 }));
elements.search.addEventListener('input', applySearch);
elements.createButton.addEventListener('click', openCreateModal);
elements.form.addEventListener('submit', createRequest);
document.querySelectorAll('[data-close-modal]').forEach((button) => button.addEventListener('click', closeCreateModal));
document.querySelector('#closeDetails').addEventListener('click', closeDetails);
elements.detailBackdrop.addEventListener('click', (event) => {
    if (event.target === elements.detailBackdrop) closeDetails();
});
document.querySelector('#mobileMenu').addEventListener('click', () => {
    document.querySelector('#sidebar').classList.toggle('open');
});
document.querySelectorAll('.nav-item').forEach((item) => item.addEventListener('click', () => {
    document.querySelectorAll('.nav-item').forEach((link) => link.classList.remove('active'));
    item.classList.add('active');
    document.querySelector('#sidebar').classList.remove('open');
}));
elements.rows.addEventListener('click', (event) => {
    const button = event.target.closest('[data-action]');
    if (!button) return;
    if (button.dataset.action === 'details') openDetails(button.dataset.id);
    if (button.dataset.action === 'submit') submitForIssuance(button.dataset.id, button);
});
elements.detailContent.addEventListener('click', (event) => {
    const button = event.target.closest('[data-action="submit"]');
    if (button) submitForIssuance(button.dataset.id, button);
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        closeCreateModal();
        closeDetails();
        document.querySelector('#sidebar').classList.remove('open');
    }
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        elements.search.focus();
    }
});

document.querySelector('#todayLabel').textContent = new Intl.DateTimeFormat('en-IN', {
    weekday: 'short',
    day: '2-digit',
    month: 'short',
    year: 'numeric',
}).format(new Date());

loadRequests({ page: 1 });
