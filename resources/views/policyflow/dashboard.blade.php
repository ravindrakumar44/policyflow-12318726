<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>PolicyFlow — Issuance workspace</title>
    <meta name="description" content="PolicyFlow insurance issuance operations workspace">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="/" aria-label="PolicyFlow home">
            <span class="brand-mark"><span></span><span></span><span></span></span>
            <span class="brand-name">policy<span>flow</span><small>ISSUANCE WORKSPACE</small></span>
        </a>

        <div class="workspace-label">WORKSPACE</div>
        <nav class="main-nav" aria-label="Main navigation">
            <a class="nav-item active" href="#overview" aria-current="page">
                <span class="nav-icon">▦</span><span>Overview</span><span class="nav-indicator"></span>
            </a>
            <a class="nav-item" href="#requests">
                <span class="nav-icon">▤</span><span>Policy requests</span>
            </a>
        </nav>

        <div class="sidebar-rule"></div>
        <div class="workspace-label">SYSTEM</div>
        <div class="system-card">
            <span class="system-dot"></span>
            <span><strong>Mock insurer</strong><small>Local environment</small></span>
            <span class="system-pulse"></span>
        </div>
        <div class="sidebar-foot">
            <div class="avatar">PF</div>
            <div class="sidebar-user"><strong>Operations</strong><span>Policy administrator</span></div>
            <span class="sidebar-more" aria-hidden="true">···</span>
        </div>
    </aside>

    <main class="main-area" id="overview">
        <header class="topbar">
            <button class="mobile-menu" id="mobileMenu" aria-label="Toggle navigation">☰</button>
            <div class="breadcrumbs"><span>Workspace</span><span class="crumb-slash">/</span><strong>Overview</strong></div>
            <div class="topbar-right">
                <span class="live-status" id="apiConnection"><span></span> Connecting to API</span>
                <span class="topbar-date" id="todayLabel"></span>
                <div class="top-avatar" aria-label="Operations profile">OP</div>
            </div>
        </header>

        <div class="page-content">
            <section class="page-heading">
                <div>
                    <div class="eyebrow"><span class="eyebrow-line"></span> OPERATIONS CENTER</div>
                    <h1>Good day, team <span class="heading-period">.</span></h1>
                    <p class="heading-description">A clear view of every request moving through your issuance pipeline.</p>
                </div>
                <div class="heading-actions">
                    <button class="button button-secondary" id="refreshButton" type="button"><span class="refresh-icon">↻</span> Refresh</button>
                    <button class="button button-primary" id="openCreateButton" type="button"><span class="plus-icon">＋</span> Create request</button>
                </div>
            </section>

            <section class="stats-grid" aria-label="Request summary">
                <article class="stat-card">
                    <div class="stat-top"><span class="stat-label">TOTAL REQUESTS</span><span class="stat-icon stat-icon-blue">▤</span></div>
                    <div class="stat-number" id="totalCount">—</div>
                    <div class="stat-foot"><span class="stat-caption">All requests received</span></div>
                    <span class="stat-watermark">01</span>
                </article>
                <article class="stat-card">
                    <div class="stat-top"><span class="stat-label">POLICIES ISSUED</span><span class="stat-icon stat-icon-green">✓</span></div>
                    <div class="stat-number" id="issuedCount">—</div>
                    <div class="stat-foot"><span class="stat-caption"><i class="tiny-dot green"></i> Successfully confirmed</span></div>
                    <span class="stat-watermark">02</span>
                </article>
                <article class="stat-card">
                    <div class="stat-top"><span class="stat-label">AWAITING CONFIRMATION</span><span class="stat-icon stat-icon-amber">◷</span></div>
                    <div class="stat-number" id="pendingCount">—</div>
                    <div class="stat-foot"><span class="stat-caption"><i class="tiny-dot amber"></i> Pending provider response</span></div>
                    <span class="stat-watermark">03</span>
                </article>
                <article class="stat-card">
                    <div class="stat-top"><span class="stat-label">FAILED REQUESTS</span><span class="stat-icon stat-icon-red">!</span></div>
                    <div class="stat-number" id="failedCount">—</div>
                    <div class="stat-foot"><span class="stat-caption"><i class="tiny-dot red"></i> Require attention</span></div>
                    <span class="stat-watermark">04</span>
                </article>
            </section>

            <section class="pipeline-banner" aria-label="Issuance workflow">
                <div class="pipeline-copy">
                    <div class="pipeline-kicker">ISSUANCE PIPELINE <span class="pipeline-rule"></span> LIVE VIEW</div>
                    <div class="pipeline-title">Every policy, <em>accounted for.</em></div>
                </div>
                <div class="pipeline-steps" aria-hidden="true">
                    <span class="pipeline-step done"><b>01</b><i></i><small>RECEIVED</small></span>
                    <span class="pipeline-step done"><b>02</b><i></i><small>VALIDATED</small></span>
                    <span class="pipeline-step current"><b>03</b><i></i><small>SUBMITTED</small></span>
                    <span class="pipeline-step"><b>04</b><i></i><small>CONFIRMED</small></span>
                </div>
                <div class="pipeline-decoration"></div>
            </section>

            <section class="requests-section" id="requests">
                <div class="section-heading">
                    <div>
                        <div class="eyebrow"><span class="eyebrow-line"></span> YOUR PORTFOLIO</div>
                        <h2>Policy requests <span class="request-total" id="tableCount">—</span></h2>
                        <p>Review applications and move eligible requests toward issuance.</p>
                    </div>
                    <div class="table-tools">
                        <label class="search-box">
                            <span aria-hidden="true">⌕</span>
                            <input id="searchInput" type="search" placeholder="Search this page" aria-label="Search requests on this page">
                            <kbd>Ctrl K</kbd>
                        </label>
                        <label class="filter-select">
                            <span class="sr-only">Filter by status</span>
                            <select id="statusFilter">
                                <option value="">All statuses</option>
                                <option>RECEIVED</option>
                                <option>VALIDATED</option>
                                <option>SUBMITTED</option>
                                <option>PENDING_CONFIRMATION</option>
                                <option>ISSUED</option>
                                <option>FAILED</option>
                                <option>NEEDS_RECONCILIATION</option>
                            </select>
                        </label>
                    </div>
                </div>

                <div class="table-card">
                    <div class="table-scroll">
                        <table>
                            <thead>
                            <tr>
                                <th>POLICY HOLDER</th>
                                <th>DESTINATION</th>
                                <th>PLAN</th>
                                <th>PREMIUM</th>
                                <th>STATUS</th>
                                <th>CREATED</th>
                                <th><span class="sr-only">Actions</span></th>
                            </tr>
                            </thead>
                            <tbody id="requestRows">
                            <tr><td colspan="7"><div class="table-loading"><span class="spinner"></span> Loading policy requests…</div></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="table-footer">
                        <span id="paginationSummary">Loading requests</span>
                        <div class="pagination">
                            <button id="previousPage" type="button" aria-label="Previous page" disabled>←</button>
                            <span id="pageNumber">—</span>
                            <button id="nextPage" type="button" aria-label="Next page" disabled>→</button>
                        </div>
                    </div>
                </div>
            </section>

            <footer class="page-footer"><span>POLICYFLOW <b>•</b> ISSUANCE OPERATIONS</span><span>Fictional insurance workflow · Local environment</span></footer>
        </div>
    </main>
</div>

<div class="modal-backdrop" id="createModal" hidden>
    <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="createTitle">
        <div class="modal-header">
            <div><div class="eyebrow"><span class="eyebrow-line"></span> NEW APPLICATION</div><h2 id="createTitle">Create policy request</h2><p>Enter the trip and applicant details to calculate a premium.</p></div>
            <button class="icon-button" type="button" data-close-modal aria-label="Close form">×</button>
        </div>
        <div class="notice notice-error" id="formError" role="alert" hidden></div>
        <div class="notice notice-success" id="formSuccess" role="status" hidden></div>
        <form id="createForm">
            <div class="form-grid">
                <label class="form-field"><span>Client request ID <b>*</b></span><input name="client_request_id" maxlength="255" required placeholder="AGT-2026-0001"><small data-error-for="client_request_id"></small></label>
                <label class="form-field"><span>Agent ID <b>*</b></span><input name="agent_id" maxlength="255" required placeholder="AGENT-17"><small data-error-for="agent_id"></small></label>
                <label class="form-field form-span-two"><span>Customer name <b>*</b></span><input name="customer_name" maxlength="255" required placeholder="Full name"><small data-error-for="customer_name"></small></label>
                <label class="form-field"><span>Date of birth <b>*</b></span><input name="date_of_birth" type="date" required><small data-error-for="date_of_birth"></small></label>
                <label class="form-field"><span>Destination <b>*</b></span><select name="destination" required><option value="">Select destination</option><option value="DOMESTIC">Domestic</option><option value="INTERNATIONAL">International</option></select><small data-error-for="destination"></small></label>
                <label class="form-field"><span>Trip start <b>*</b></span><input name="trip_start" type="date" required><small data-error-for="trip_start"></small></label>
                <label class="form-field"><span>Trip end <b>*</b></span><input name="trip_end" type="date" required><small data-error-for="trip_end"></small></label>
                <label class="form-field"><span>Plan code <b>*</b></span><select name="plan_code" required><option value="">Select plan</option><option value="BASIC">Basic</option><option value="PLUS">Plus</option><option value="PREMIUM">Premium</option></select><small data-error-for="plan_code"></small></label>
                <label class="form-field"><span>Sum insured <b>*</b></span><select name="sum_insured" required><option value="">Select coverage</option><option value="100000">₹100,000</option><option value="300000">₹300,000</option><option value="500000">₹500,000</option></select><small data-error-for="sum_insured"></small></label>
            </div>
            <div class="form-note"><span>ⓘ</span> Premium is calculated using the assignment's fictional rates and saved with this request.</div>
            <div class="modal-actions"><button class="button button-secondary" type="button" data-close-modal>Cancel</button><button class="button button-primary" id="createSubmit" type="submit"><span class="button-label">Calculate &amp; create</span><span class="button-spinner spinner" hidden></span></button></div>
        </form>
    </section>
</div>

<div class="detail-backdrop" id="detailBackdrop" hidden>
    <aside class="detail-panel" role="dialog" aria-modal="true" aria-labelledby="detailTitle">
        <div class="detail-header">
            <div><div class="eyebrow"><span class="eyebrow-line"></span> REQUEST DETAILS</div><h2 id="detailTitle">Policy request</h2><span class="detail-id" id="detailRequestId"></span></div>
            <button class="icon-button" id="closeDetails" type="button" aria-label="Close details">×</button>
        </div>
        <div id="detailContent"><div class="table-loading"><span class="spinner"></span> Loading request details…</div></div>
    </aside>
</div>

<div class="toast-region" id="toastRegion" aria-live="polite" aria-atomic="true"></div>
</body>
</html>
