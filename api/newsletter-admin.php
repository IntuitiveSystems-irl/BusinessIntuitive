<?php
require_once __DIR__ . '/config.php';
/**
 * Newsletter Admin Panel
 * Manages clients, composes newsletters, previews & sends
 * Styled to match the "newspaper × Breakfast at Tiffany's" aesthetic
 */

// ── Basic Auth Protection ──
$ADMIN_USER = 'bi-admin';
$ADMIN_PASS = 'TheDiagnostic2026!';

if (!isset($_SERVER['PHP_AUTH_USER']) ||
    $_SERVER['PHP_AUTH_USER'] !== $ADMIN_USER ||
    $_SERVER['PHP_AUTH_PW'] !== $ADMIN_PASS) {
    header('WWW-Authenticate: Basic realm="The Diagnostic Admin"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Access denied.';
    exit;
}

require_once __DIR__ . '/newsletter-db.php';
$db = getDB();

// Handle inline preview request
if (isset($_GET['preview']) && isset($_GET['id'])) {
    require_once __DIR__ . '/newsletter-send.php';
    $stmt = $db->prepare('SELECT * FROM newsletters WHERE id = :id');
    $stmt->bindValue(':id', (int)$_GET['id'], SQLITE3_INTEGER);
    $result = $stmt->execute();
    $newsletter = $result->fetchArray(SQLITE3_ASSOC);
    if ($newsletter) {
        $previewClient = [
            'id' => 0, 'name' => 'Jane Doe', 'email' => 'jane@example.com',
            'company' => 'Acme Corp', 'website_url' => 'https://example.com', 'platform_url' => '',
        ];
        echo buildNewsletterHTML($newsletter, $previewClient);
    } else {
        echo '<p>Newsletter not found.</p>';
    }
    exit;
}

// Get stats
$clientCount = $db->querySingle('SELECT COUNT(*) FROM clients WHERE active = 1');
$newsletterCount = $db->querySingle('SELECT COUNT(*) FROM newsletters');
$sentCount = $db->querySingle("SELECT COUNT(*) FROM newsletters WHERE status = 'sent'");
$totalEmails = $db->querySingle('SELECT COUNT(*) FROM send_log');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>The Diagnostic — Newsletter Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500;600&family=JetBrains+Mono:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #050505;
            --surface: #0A0A0A;
            --surface2: #0F0F0F;
            --border: #1A1A1A;
            --border2: #2A2A2A;
            --text: #E0E0E0;
            --text-dim: #888;
            --text-muted: #555;
            --tiffany: #81D8D0;
            --tiffany-dim: rgba(129,216,208,0.15);
            --gold: #C9A84C;
            --gold-dim: rgba(201,168,76,0.15);
            --danger: #E05555;
            --danger-dim: rgba(224,85,85,0.12);
            --success: #55C98A;
            --font-serif: 'Playfair Display', Georgia, serif;
            --font-sans: 'Inter', -apple-system, sans-serif;
            --font-mono: 'JetBrains Mono', 'Courier New', monospace;
        }
        *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
        body {
            background: var(--bg);
            color: var(--text);
            font-family: var(--font-sans);
            font-size: 14px;
            line-height: 1.6;
            min-height: 100vh;
        }
        a { color: var(--tiffany); text-decoration: none; }
        a:hover { text-decoration: underline; }

        /* ── LAYOUT ── */
        .admin-shell { display: flex; min-height: 100vh; }
        .sidebar {
            width: 240px;
            background: var(--surface);
            border-right: 1px solid var(--border);
            padding: 32px 0;
            position: fixed;
            top: 0; left: 0; bottom: 0;
            overflow-y: auto;
            z-index: 50;
        }
        .main {
            margin-left: 240px;
            flex: 1;
            padding: 40px;
            max-width: 1100px;
        }

        /* ── SIDEBAR ── */
        .sidebar-brand {
            padding: 0 24px 28px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 20px;
        }
        .sidebar-brand h1 {
            font-family: var(--font-serif);
            font-size: 18px;
            font-weight: 400;
            letter-spacing: 2px;
            color: #FFF;
        }
        .sidebar-brand p {
            font-family: var(--font-mono);
            font-size: 9px;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: var(--tiffany);
            margin-top: 4px;
        }
        .sidebar-nav { list-style: none; }
        .sidebar-nav li a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 24px;
            font-family: var(--font-mono);
            font-size: 11px;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: var(--text-dim);
            text-decoration: none;
            transition: all 0.2s;
            border-left: 2px solid transparent;
        }
        .sidebar-nav li a:hover,
        .sidebar-nav li a.active {
            color: var(--tiffany);
            background: var(--tiffany-dim);
            border-left-color: var(--tiffany);
        }
        .sidebar-nav li a .nav-icon {
            font-size: 16px;
            width: 20px;
            text-align: center;
        }
        .sidebar-stats {
            padding: 20px 24px;
            margin-top: 20px;
            border-top: 1px solid var(--border);
        }
        .sidebar-stats .stat-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-family: var(--font-mono);
            font-size: 10px;
            color: var(--text-muted);
            letter-spacing: 1px;
        }
        .sidebar-stats .stat-row span:last-child {
            color: var(--tiffany);
            font-weight: 500;
        }

        /* ── PAGE HEADERS ── */
        .page-header {
            margin-bottom: 32px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border);
        }
        .page-header h2 {
            font-family: var(--font-serif);
            font-size: 28px;
            font-weight: 400;
            color: #FFF;
            margin-bottom: 4px;
        }
        .page-header p {
            font-family: var(--font-mono);
            font-size: 11px;
            color: var(--text-muted);
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        /* ── TABS ── */
        .tab-bar {
            display: flex;
            gap: 0;
            border-bottom: 1px solid var(--border);
            margin-bottom: 28px;
        }
        .tab-btn {
            background: none;
            border: none;
            padding: 12px 24px;
            font-family: var(--font-mono);
            font-size: 11px;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--text-muted);
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
        }
        .tab-btn:hover { color: var(--text); }
        .tab-btn.active {
            color: var(--tiffany);
            border-bottom-color: var(--tiffany);
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* ── CARDS & TABLES ── */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            padding: 24px;
            margin-bottom: 20px;
        }
        .card-title {
            font-family: var(--font-serif);
            font-size: 18px;
            font-weight: 400;
            color: #FFF;
            margin-bottom: 16px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            font-family: var(--font-mono);
            font-size: 9px;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--text-muted);
            text-align: left;
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
        }
        td {
            padding: 12px;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
            color: var(--text-dim);
            vertical-align: middle;
        }
        tr:hover td { background: rgba(255,255,255,0.015); }

        /* ── FORMS ── */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .form-grid .full-width { grid-column: 1 / -1; }
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            font-family: var(--font-mono);
            font-size: 10px;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 6px;
        }
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px 14px;
            background: var(--surface2);
            border: 1px solid var(--border2);
            color: var(--text);
            font-family: var(--font-sans);
            font-size: 13px;
            transition: border-color 0.2s;
            border-radius: 0;
            -webkit-appearance: none;
        }
        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--tiffany);
        }
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
            line-height: 1.6;
        }

        /* ── BUTTONS ── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border: none;
            font-family: var(--font-mono);
            font-size: 10px;
            letter-spacing: 2px;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.2s;
            border-radius: 0;
        }
        .btn-primary {
            background: var(--tiffany);
            color: var(--bg);
        }
        .btn-primary:hover {
            box-shadow: 0 4px 20px rgba(129,216,208,0.25);
            transform: translateY(-1px);
        }
        .btn-gold {
            background: var(--gold);
            color: var(--bg);
        }
        .btn-gold:hover {
            box-shadow: 0 4px 20px rgba(201,168,76,0.25);
            transform: translateY(-1px);
        }
        .btn-outline {
            background: transparent;
            border: 1px solid var(--border2);
            color: var(--text-dim);
        }
        .btn-outline:hover {
            border-color: var(--tiffany);
            color: var(--tiffany);
        }
        .btn-danger {
            background: transparent;
            border: 1px solid var(--danger);
            color: var(--danger);
        }
        .btn-danger:hover {
            background: var(--danger-dim);
        }
        .btn-sm { padding: 6px 12px; font-size: 9px; }
        .btn-group { display: flex; gap: 8px; margin-top: 16px; flex-wrap: wrap; }

        /* ── BADGES ── */
        .badge {
            display: inline-block;
            padding: 3px 10px;
            font-family: var(--font-mono);
            font-size: 9px;
            letter-spacing: 1px;
            text-transform: uppercase;
            border-radius: 0;
        }
        .badge-draft { background: var(--border2); color: var(--text-muted); }
        .badge-scheduled { background: var(--gold-dim); color: var(--gold); border: 1px solid rgba(201,168,76,0.3); }
        .badge-sent { background: rgba(85,201,138,0.12); color: var(--success); border: 1px solid rgba(85,201,138,0.3); }
        .badge-active { background: var(--tiffany-dim); color: var(--tiffany); border: 1px solid rgba(129,216,208,0.3); }
        .badge-inactive { background: var(--danger-dim); color: var(--danger); border: 1px solid rgba(224,85,85,0.3); }

        /* ── TOAST ── */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }
        .toast {
            background: var(--surface);
            border: 1px solid var(--border);
            border-left: 3px solid var(--tiffany);
            padding: 14px 20px;
            margin-bottom: 8px;
            font-size: 13px;
            color: var(--text);
            animation: toastIn 0.3s ease;
            min-width: 280px;
        }
        .toast.error { border-left-color: var(--danger); }
        @keyframes toastIn {
            from { opacity: 0; transform: translateX(40px); }
            to { opacity: 1; transform: translateX(0); }
        }

        /* ── PREVIEW MODAL ── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.8);
            z-index: 100;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.open { display: flex; }
        .modal-frame {
            background: var(--surface);
            border: 1px solid var(--border);
            width: 90%;
            max-width: 700px;
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .modal-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 20px;
            border-bottom: 1px solid var(--border);
        }
        .modal-bar span {
            font-family: var(--font-mono);
            font-size: 10px;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: var(--text-muted);
        }
        .modal-close {
            background: none;
            border: 1px solid var(--border2);
            color: var(--text-dim);
            width: 28px;
            height: 28px;
            cursor: pointer;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .modal-close:hover { border-color: var(--tiffany); color: var(--tiffany); }
        .modal-body { flex: 1; overflow-y: auto; }
        .modal-body iframe { width: 100%; height: 80vh; border: none; }

        /* ── RESPONSIVE ── */
        @media (max-width: 900px) {
            .sidebar { width: 200px; }
            .main { margin-left: 200px; padding: 24px; }
            .form-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .sidebar { display: none; }
            .main { margin-left: 0; }
        }

        /* ── EMPTY STATE ── */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }
        .empty-state .empty-icon {
            font-size: 40px;
            margin-bottom: 16px;
            opacity: 0.3;
        }
        .empty-state p {
            font-family: var(--font-serif);
            font-size: 16px;
            font-style: italic;
            color: var(--text-muted);
        }
    </style>
</head>
<body>

<div class="admin-shell">

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <h1>The Diagnostic</h1>
            <p>Newsletter Admin</p>
        </div>
        <ul class="sidebar-nav">
            <li><a href="#" class="active" data-tab="dashboard"><span class="nav-icon">&#9670;</span> Dashboard</a></li>
            <li><a href="#" data-tab="clients"><span class="nav-icon">&#9632;</span> Clients</a></li>
            <li><a href="#" data-tab="compose"><span class="nav-icon">&#9998;</span> Compose</a></li>
            <li><a href="#" data-tab="newsletters"><span class="nav-icon">&#9993;</span> Newsletters</a></li>
            <li><a href="#" data-tab="datasources"><span class="nav-icon">&#9881;</span> Data Sources</a></li>
            <li><a href="#" data-tab="analytics"><span class="nav-icon">&#9733;</span> CEO Analytics</a></li>
        </ul>
        <div class="sidebar-stats">
            <div class="stat-row"><span>Active Clients</span><span><?= $clientCount ?></span></div>
            <div class="stat-row"><span>Total Issues</span><span><?= $newsletterCount ?></span></div>
            <div class="stat-row"><span>Sent</span><span><?= $sentCount ?></span></div>
            <div class="stat-row"><span>Emails Delivered</span><span><?= $totalEmails ?></span></div>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main">

        <!-- ═══ DASHBOARD ═══ -->
        <div class="tab-content active" id="tab-dashboard">
            <div class="page-header">
                <h2>Dashboard</h2>
                <p>Weekly intelligence operations</p>
            </div>
            <div class="form-grid">
                <div class="card">
                    <div class="card-title">Quick Actions</div>
                    <div class="btn-group" style="flex-direction:column;">
                        <button class="btn btn-primary" onclick="switchTab('compose')">&#9998; &nbsp;Compose New Issue</button>
                        <button class="btn btn-outline" onclick="switchTab('clients')">&#9632; &nbsp;Manage Clients</button>
                        <button class="btn btn-outline" onclick="switchTab('newsletters')">&#9993; &nbsp;View All Issues</button>
                    </div>
                </div>
                <div class="card">
                    <div class="card-title">System Status</div>
                    <table>
                        <tr><td style="border:none;padding:6px 0;"><span style="color:var(--text-muted)">Active subscribers</span></td><td style="border:none;padding:6px 0;text-align:right;color:var(--tiffany)"><?= $clientCount ?></td></tr>
                        <tr><td style="border:none;padding:6px 0;"><span style="color:var(--text-muted)">Newsletters created</span></td><td style="border:none;padding:6px 0;text-align:right;color:var(--tiffany)"><?= $newsletterCount ?></td></tr>
                        <tr><td style="border:none;padding:6px 0;"><span style="color:var(--text-muted)">Total emails sent</span></td><td style="border:none;padding:6px 0;text-align:right;color:var(--tiffany)"><?= $totalEmails ?></td></tr>
                        <tr><td style="border:none;padding:6px 0;"><span style="color:var(--text-muted)">Sender address</span></td><td style="border:none;padding:6px 0;text-align:right;font-size:11px;color:var(--text-dim)">newsletter@businessintuitive.tech</td></tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- ═══ CLIENTS ═══ -->
        <div class="tab-content" id="tab-clients">
            <div class="page-header">
                <h2>Client Directory</h2>
                <p>Active newsletter recipients</p>
            </div>

            <!-- Add Client Form -->
            <div class="card" id="clientFormCard">
                <div class="card-title" id="clientFormTitle">Add New Client</div>
                <input type="hidden" id="clientEditId" value="">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Name *</label>
                        <input type="text" id="clientName" placeholder="Jane Doe">
                    </div>
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" id="clientEmail" placeholder="jane@company.com">
                    </div>
                    <div class="form-group">
                        <label>Company</label>
                        <input type="text" id="clientCompany" placeholder="Acme Corp">
                    </div>
                    <div class="form-group">
                        <label>Website URL</label>
                        <input type="url" id="clientWebsite" placeholder="https://acme.com">
                    </div>
                    <div class="form-group">
                        <label>Platform URL</label>
                        <input type="url" id="clientPlatform" placeholder="https://app.acme.com">
                    </div>
                    <div class="form-group">
                        <label>Notes</label>
                        <input type="text" id="clientNotes" placeholder="Any relevant notes">
                    </div>
                </div>
                <div class="btn-group">
                    <button class="btn btn-primary" onclick="saveClient()">Save Client</button>
                    <button class="btn btn-outline" onclick="resetClientForm()" id="cancelEditBtn" style="display:none;">Cancel</button>
                </div>
            </div>

            <!-- Client List -->
            <div class="card">
                <div class="card-title">All Clients</div>
                <div id="clientsTableWrap">
                    <div class="empty-state"><div class="empty-icon">&#9632;</div><p>Loading clients...</p></div>
                </div>
            </div>
        </div>

        <!-- ═══ COMPOSE ═══ -->
        <div class="tab-content" id="tab-compose">
            <div class="page-header">
                <h2>Compose Issue</h2>
                <p>Draft your weekly intelligence</p>
            </div>

            <!-- AI GENERATE CARD -->
            <div class="card" style="border:1px solid #C9A84C;">
                <div class="card-title" style="color:#C9A84C;">&#9733; AI Generate Content</div>
                <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px;">Enter a topic and GPT-4o will draft the newsletter sections for you. Review and edit before saving.</p>
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Topic / Theme *</label>
                        <input type="text" id="aiTopic" placeholder="e.g. Decision fatigue in scaling businesses, AI replacing middle management, Hidden costs of tool sprawl">
                    </div>
                    <div class="form-group full-width">
                        <label>Sections to Generate</label>
                        <div style="display:flex;gap:16px;flex-wrap:wrap;">
                            <label style="font-size:12px;color:var(--text-dim);display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="checkbox" id="aiSecHotNews" checked> Hot News</label>
                            <label style="font-size:12px;color:var(--text-dim);display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="checkbox" id="aiSecInsight" checked> Client Insight</label>
                            <label style="font-size:12px;color:var(--text-dim);display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="checkbox" id="aiSecExtra" checked> Extra Section</label>
                        </div>
                    </div>
                    <div class="form-group full-width">
                        <label>Tone / Direction Notes (optional)</label>
                        <input type="text" id="aiToneNotes" placeholder="e.g. Make it more provocative, focus on service businesses, tie in recent layoffs">
                    </div>
                </div>
                <div class="btn-group">
                    <button class="btn btn-primary" style="background:#C9A84C;border-color:#C9A84C;color:#050505;" onclick="aiGenerate()" id="aiGenerateBtn">&#9733; Generate with AI</button>
                    <span id="aiStatus" style="font-size:11px;color:var(--text-muted);align-self:center;"></span>
                </div>
            </div>

            <div class="card">
                <input type="hidden" id="newsletterEditId" value="">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Email Subject Line *</label>
                        <input type="text" id="nlSubject" placeholder="The Diagnostic — Week 15: The Hidden Cost of Decision Drag">
                    </div>
                    <div class="form-group full-width">
                        <label>Hot News Headline *</label>
                        <input type="text" id="nlHotTitle" placeholder="Your bold headline goes here">
                    </div>
                    <div class="form-group full-width">
                        <label>Hot News Body *</label>
                        <textarea id="nlHotBody" placeholder="The main article content. Write as much as you need — it'll format beautifully."></textarea>
                    </div>
                    <div class="form-group full-width">
                        <label>Hot News Link (optional)</label>
                        <input type="url" id="nlHotLink" placeholder="https://article-or-resource-link.com">
                    </div>
                    <div class="form-group full-width" style="border-top:1px solid var(--border);padding-top:16px;">
                        <label>Client Insight Text (personalized per-client section)</label>
                        <textarea id="nlInsight" placeholder="This appears in each client's email as a personalized insight. Reference their website or platform if relevant."></textarea>
                    </div>
                    <div class="form-group full-width" style="border-top:1px solid var(--border);padding-top:16px;">
                        <label>Extra Section Title (optional)</label>
                        <input type="text" id="nlExtraTitle" placeholder="e.g., 'A Note on AI in Operations'">
                    </div>
                    <div class="form-group full-width">
                        <label>Extra Section Body (optional)</label>
                        <textarea id="nlExtraBody" placeholder="Bonus content — a dispatch, a quick thought, a recommendation."></textarea>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select id="nlStatus">
                            <option value="draft">Draft</option>
                            <option value="scheduled">Scheduled</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Scheduled Date/Time</label>
                        <input type="datetime-local" id="nlSchedule">
                    </div>
                </div>
                <div class="btn-group">
                    <button class="btn btn-primary" onclick="saveNewsletter()">Save Newsletter</button>
                    <button class="btn btn-outline" onclick="resetComposeForm()" id="cancelNlEditBtn" style="display:none;">Cancel</button>
                </div>
            </div>
        </div>

        <!-- ═══ NEWSLETTERS ═══ -->
        <div class="tab-content" id="tab-newsletters">
            <div class="page-header">
                <h2>All Issues</h2>
                <p>The Diagnostic archive</p>
            </div>
            <div class="card">
                <div id="newslettersTableWrap">
                    <div class="empty-state"><div class="empty-icon">&#9993;</div><p>Loading issues...</p></div>
                </div>
            </div>
        </div>

        <!-- ═══ DATA SOURCES ═══ -->
        <div class="tab-content" id="tab-datasources">
            <div class="page-header">
                <h2>Data Sources</h2>
                <p>News feed, website insights &amp; Google profiles</p>
            </div>

            <!-- Fetch Controls -->
            <div class="card">
                <div class="card-title">Fetch Data</div>
                <div class="btn-group">
                    <button class="btn btn-primary" onclick="fetchData('all')">Fetch All Sources</button>
                    <button class="btn btn-outline" onclick="fetchData('news')">News Only</button>
                    <button class="btn btn-outline" onclick="fetchData('pagespeed')">PageSpeed Only</button>
                    <button class="btn btn-outline" onclick="fetchData('gbp')">Google Profiles Only</button>
                </div>
                <p style="margin-top:12px;font-size:11px;color:var(--text-muted);">Cron runs automatically every Monday at 7am Pacific. Use these buttons for manual fetches.</p>
            </div>

            <!-- News Feed -->
            <div class="card">
                <div class="card-title">News Feed</div>
                <div id="newsFeedWrap">
                    <div class="empty-state"><div class="empty-icon">&#9993;</div><p>Loading news...</p></div>
                </div>
            </div>

            <!-- Website Reports -->
            <div class="card">
                <div class="card-title">Website Performance Reports</div>
                <div id="reportsWrap">
                    <div class="empty-state"><div class="empty-icon">&#9881;</div><p>Loading reports...</p></div>
                </div>
            </div>

            <!-- Google Connections -->
            <div class="card">
                <div class="card-title">Google Business Profile Connections</div>
                <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">Connect each client's Google Business Profile to pull reviews, impressions, clicks, and calls into their newsletter.</p>
                <div id="googleConnsWrap">
                    <div class="empty-state"><div class="empty-icon">&#9670;</div><p>Loading connections...</p></div>
                </div>
            </div>
        </div>

        <!-- ═══ CEO ANALYTICS ═══ -->
        <div class="tab-content" id="tab-analytics">
            <div class="page-header">
                <h2>CEO Funnel Analytics</h2>
                <p>Visitor tracking &mdash; pageviews, scroll depth, clicks, geography</p>
            </div>

            <!-- Period selector -->
            <div style="display:flex;gap:8px;margin-bottom:24px;flex-wrap:wrap;align-items:center;">
                <button class="btn btn-outline btn-sm ana-period active" data-days="1">Today</button>
                <button class="btn btn-outline btn-sm ana-period" data-days="7">7 Days</button>
                <button class="btn btn-outline btn-sm ana-period" data-days="30">30 Days</button>
                <button class="btn btn-outline btn-sm ana-period" data-days="90">90 Days</button>
                <button class="btn btn-primary btn-sm" onclick="loadAnalytics()" style="margin-left:auto;">&#8635; Refresh</button>
            </div>

            <!-- Stat cards row -->
            <div class="form-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px;" id="anaStatsRow">
                <div class="card" style="text-align:center;padding:18px 12px;">
                    <div style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted);letter-spacing:2px;text-transform:uppercase;">Total Views</div>
                    <div id="anaTotalViews" style="font-family:var(--font-serif);font-size:32px;color:#FFF;margin-top:6px;">—</div>
                </div>
                <div class="card" style="text-align:center;padding:18px 12px;">
                    <div style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted);letter-spacing:2px;text-transform:uppercase;">Unique Visitors</div>
                    <div id="anaTotalSessions" style="font-family:var(--font-serif);font-size:32px;color:#FFF;margin-top:6px;">—</div>
                </div>
                <div class="card" style="text-align:center;padding:18px 12px;">
                    <div style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted);letter-spacing:2px;text-transform:uppercase;">Today Views</div>
                    <div id="anaTodayViews" style="font-family:var(--font-serif);font-size:32px;color:var(--tiffany);margin-top:6px;">—</div>
                </div>
                <div class="card" style="text-align:center;padding:18px 12px;">
                    <div style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted);letter-spacing:2px;text-transform:uppercase;">Today Visitors</div>
                    <div id="anaTodaySessions" style="font-family:var(--font-serif);font-size:32px;color:var(--tiffany);margin-top:6px;">—</div>
                </div>
            </div>

            <!-- Daily chart (text-based) -->
            <div class="card" id="anaDailyCard">
                <div class="card-title">Daily Traffic</div>
                <div id="anaDailyChart" style="font-family:var(--font-mono);font-size:11px;line-height:1.8;color:var(--text-dim);"></div>
            </div>

            <!-- Two-column: Pages + Referrers -->
            <div class="form-grid">
                <div class="card">
                    <div class="card-title">Top Pages</div>
                    <div id="anaPages"></div>
                </div>
                <div class="card">
                    <div class="card-title">Top Referrers</div>
                    <div id="anaReferrers"></div>
                </div>
            </div>

            <!-- Two-column: Geography + Devices -->
            <div class="form-grid">
                <div class="card">
                    <div class="card-title">Visitor Locations</div>
                    <div id="anaGeo"></div>
                </div>
                <div class="card">
                    <div class="card-title">Devices &amp; Browsers</div>
                    <div id="anaDevices"></div>
                </div>
            </div>

            <!-- Two-column: Scroll Depth + Top Clicks -->
            <div class="form-grid">
                <div class="card">
                    <div class="card-title">Scroll Depth (Avg %)</div>
                    <div id="anaScrolls"></div>
                </div>
                <div class="card">
                    <div class="card-title">Top Clicked Elements</div>
                    <div id="anaClicks"></div>
                </div>
            </div>

            <!-- Two-column: Time on Page + UTM Sources -->
            <div class="form-grid">
                <div class="card">
                    <div class="card-title">Avg. Time on Page</div>
                    <div id="anaDurations"></div>
                </div>
                <div class="card">
                    <div class="card-title">UTM Campaign Sources</div>
                    <div id="anaUtms"></div>
                </div>
            </div>

            <!-- Recent Visitors (live feed) -->
            <div class="card">
                <div class="card-title">Recent Visitors</div>
                <div id="anaVisitors"></div>
            </div>
        </div>

    </main>
</div>

<!-- PREVIEW MODAL -->
<div class="modal-overlay" id="previewModal">
    <div class="modal-frame">
        <div class="modal-bar">
            <span>Email Preview</span>
            <button class="modal-close" onclick="closePreview()">&times;</button>
        </div>
        <div class="modal-body">
            <iframe id="previewFrame"></iframe>
        </div>
    </div>
</div>

<!-- TOAST CONTAINER -->
<div class="toast-container" id="toasts"></div>

<script>
const API_BASE = '/api';
const API_KEY = '<?= NEWSLETTER_API_KEY ?>';

// ── Tab Navigation ──
function switchTab(name) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.sidebar-nav a').forEach(el => el.classList.remove('active'));
    document.getElementById('tab-' + name)?.classList.add('active');
    document.querySelector(`.sidebar-nav a[data-tab="${name}"]`)?.classList.add('active');
    if (name === 'clients') loadClients();
    if (name === 'newsletters') loadNewsletters();
    if (name === 'datasources') loadDataSources();
    if (name === 'analytics') loadAnalytics();
}
document.querySelectorAll('.sidebar-nav a').forEach(link => {
    link.addEventListener('click', e => {
        e.preventDefault();
        switchTab(link.dataset.tab);
    });
});

// ── Toast Notifications ──
function toast(msg, isError = false) {
    const el = document.createElement('div');
    el.className = 'toast' + (isError ? ' error' : '');
    el.textContent = msg;
    document.getElementById('toasts').appendChild(el);
    setTimeout(() => el.remove(), 4000);
}

// ── API Helper ──
async function api(endpoint, method = 'GET', body = null) {
    const opts = { method, headers: { 'Content-Type': 'application/json', 'X-API-Key': API_KEY } };
    if (body) opts.body = JSON.stringify(body);
    const url = method === 'DELETE' || method === 'GET'
        ? `${API_BASE}/${endpoint}`
        : `${API_BASE}/${endpoint}`;
    const res = await fetch(url, opts);
    return res.json();
}

// ══════════════════════════════════════
// CLIENTS
// ══════════════════════════════════════
async function loadClients() {
    const data = await api('newsletter-clients.php');
    const wrap = document.getElementById('clientsTableWrap');
    if (!data.success || data.clients.length === 0) {
        wrap.innerHTML = '<div class="empty-state"><div class="empty-icon">&#9632;</div><p>No clients yet. Add your first client above.</p></div>';
        return;
    }
    let html = `<table><thead><tr>
        <th>Name</th><th>Email</th><th>Company</th><th>Website</th><th>Status</th><th>Actions</th>
    </tr></thead><tbody>`;
    data.clients.forEach(c => {
        const badge = c.active == 1
            ? '<span class="badge badge-active">Active</span>'
            : '<span class="badge badge-inactive">Inactive</span>';
        const website = c.website_url ? `<a href="${c.website_url}" target="_blank" style="font-size:11px;">${c.website_url.replace(/^https?:\/\//, '').substring(0,30)}</a>` : '—';
        html += `<tr>
            <td style="color:#FFF;font-weight:500;">${esc(c.name)}</td>
            <td>${esc(c.email)}</td>
            <td>${esc(c.company || '—')}</td>
            <td>${website}</td>
            <td>${badge}</td>
            <td>
                <button class="btn btn-outline btn-sm" onclick="editClient(${c.id})">Edit</button>
                <button class="btn btn-danger btn-sm" onclick="deleteClient(${c.id}, '${esc(c.name)}')">Del</button>
            </td>
        </tr>`;
    });
    html += '</tbody></table>';
    wrap.innerHTML = html;
}

async function saveClient() {
    const id = document.getElementById('clientEditId').value;
    const payload = {
        name: document.getElementById('clientName').value.trim(),
        email: document.getElementById('clientEmail').value.trim(),
        company: document.getElementById('clientCompany').value.trim(),
        website_url: document.getElementById('clientWebsite').value.trim(),
        platform_url: document.getElementById('clientPlatform').value.trim(),
        notes: document.getElementById('clientNotes').value.trim(),
        active: 1,
    };
    if (!payload.name || !payload.email) { toast('Name and email are required.', true); return; }

    let data;
    if (id) {
        payload.id = parseInt(id);
        data = await api('newsletter-clients.php', 'PUT', payload);
    } else {
        data = await api('newsletter-clients.php', 'POST', payload);
    }
    if (data.success) {
        toast(id ? 'Client updated.' : 'Client added.');
        resetClientForm();
        loadClients();
    } else {
        toast(data.error || 'Failed to save client.', true);
    }
}

async function editClient(id) {
    const data = await api(`newsletter-clients.php?id=${id}`);
    if (!data.success) return;
    const c = data.client;
    document.getElementById('clientEditId').value = c.id;
    document.getElementById('clientName').value = c.name;
    document.getElementById('clientEmail').value = c.email;
    document.getElementById('clientCompany').value = c.company || '';
    document.getElementById('clientWebsite').value = c.website_url || '';
    document.getElementById('clientPlatform').value = c.platform_url || '';
    document.getElementById('clientNotes').value = c.notes || '';
    document.getElementById('clientFormTitle').textContent = 'Edit Client';
    document.getElementById('cancelEditBtn').style.display = '';
    document.getElementById('clientFormCard').scrollIntoView({ behavior: 'smooth' });
}

function resetClientForm() {
    document.getElementById('clientEditId').value = '';
    ['clientName','clientEmail','clientCompany','clientWebsite','clientPlatform','clientNotes'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('clientFormTitle').textContent = 'Add New Client';
    document.getElementById('cancelEditBtn').style.display = 'none';
}

async function deleteClient(id, name) {
    if (!confirm(`Delete client "${name}"? This cannot be undone.`)) return;
    const data = await api(`newsletter-clients.php?id=${id}`, 'DELETE');
    if (data.success) { toast('Client deleted.'); loadClients(); }
    else toast(data.error || 'Failed.', true);
}

// ══════════════════════════════════════
// NEWSLETTERS
// ══════════════════════════════════════
async function loadNewsletters() {
    const data = await api('newsletter-manage.php');
    const wrap = document.getElementById('newslettersTableWrap');
    if (!data.success || data.newsletters.length === 0) {
        wrap.innerHTML = '<div class="empty-state"><div class="empty-icon">&#9993;</div><p>No newsletters yet. Compose your first issue.</p></div>';
        return;
    }
    let html = `<table><thead><tr>
        <th>Subject</th><th>Status</th><th>Scheduled</th><th>Sent</th><th>Actions</th>
    </tr></thead><tbody>`;
    data.newsletters.forEach(n => {
        const statusBadge = n.status === 'sent' ? '<span class="badge badge-sent">Sent</span>'
            : n.status === 'scheduled' ? '<span class="badge badge-scheduled">Scheduled</span>'
            : '<span class="badge badge-draft">Draft</span>';
        html += `<tr>
            <td style="color:#FFF;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(n.subject)}</td>
            <td>${statusBadge}</td>
            <td style="font-size:11px;">${n.scheduled_at || '—'}</td>
            <td style="font-size:11px;">${n.sent_at || '—'}</td>
            <td style="white-space:nowrap;">
                <button class="btn btn-outline btn-sm" onclick="previewNewsletter(${n.id})">Preview</button>
                <button class="btn btn-outline btn-sm" onclick="editNewsletter(${n.id})">Edit</button>
                <button class="btn btn-gold btn-sm" onclick="sendNewsletter(${n.id})">Send</button>
                <button class="btn btn-outline btn-sm" onclick="testNewsletter(${n.id})">Test</button>
                <button class="btn btn-danger btn-sm" onclick="deleteNewsletter(${n.id})">Del</button>
            </td>
        </tr>`;
    });
    html += '</tbody></table>';
    wrap.innerHTML = html;
}

async function saveNewsletter() {
    const id = document.getElementById('newsletterEditId').value;
    const payload = {
        subject: document.getElementById('nlSubject').value.trim(),
        hot_news_title: document.getElementById('nlHotTitle').value.trim(),
        hot_news_body: document.getElementById('nlHotBody').value.trim(),
        hot_news_link: document.getElementById('nlHotLink').value.trim(),
        insight_text: document.getElementById('nlInsight').value.trim(),
        extra_section_title: document.getElementById('nlExtraTitle').value.trim(),
        extra_section_body: document.getElementById('nlExtraBody').value.trim(),
        status: document.getElementById('nlStatus').value,
        scheduled_at: document.getElementById('nlSchedule').value || null,
    };
    if (!payload.subject || !payload.hot_news_title || !payload.hot_news_body) {
        toast('Subject, headline, and body are required.', true);
        return;
    }
    let data;
    if (id) {
        payload.id = parseInt(id);
        data = await api('newsletter-manage.php', 'PUT', payload);
    } else {
        data = await api('newsletter-manage.php', 'POST', payload);
    }
    if (data.success) {
        toast(id ? 'Newsletter updated.' : 'Newsletter saved.');
        resetComposeForm();
    } else {
        toast(data.error || 'Failed to save.', true);
    }
}

async function editNewsletter(id) {
    const data = await api(`newsletter-manage.php?id=${id}`);
    if (!data.success) return;
    const n = data.newsletter;
    document.getElementById('newsletterEditId').value = n.id;
    document.getElementById('nlSubject').value = n.subject;
    document.getElementById('nlHotTitle').value = n.hot_news_title;
    document.getElementById('nlHotBody').value = n.hot_news_body;
    document.getElementById('nlHotLink').value = n.hot_news_link || '';
    document.getElementById('nlInsight').value = n.insight_text || '';
    document.getElementById('nlExtraTitle').value = n.extra_section_title || '';
    document.getElementById('nlExtraBody').value = n.extra_section_body || '';
    document.getElementById('nlStatus').value = n.status;
    document.getElementById('nlSchedule').value = n.scheduled_at || '';
    document.getElementById('cancelNlEditBtn').style.display = '';
    switchTab('compose');
}

function resetComposeForm() {
    document.getElementById('newsletterEditId').value = '';
    ['nlSubject','nlHotTitle','nlHotBody','nlHotLink','nlInsight','nlExtraTitle','nlExtraBody','nlSchedule'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('nlStatus').value = 'draft';
    document.getElementById('cancelNlEditBtn').style.display = 'none';
}

async function sendNewsletter(id) {
    if (!confirm('Send this newsletter to ALL active clients now?')) return;
    toast('Sending newsletter...');
    const data = await api('newsletter-send.php', 'POST', { newsletter_id: id });
    if (data.success) {
        toast(`Sent to ${data.sent} client(s). ${data.failed} failed.`);
        loadNewsletters();
    } else {
        toast(data.error || 'Send failed.', true);
    }
}

async function testNewsletter(id) {
    const email = prompt('Send test email to:', 'lbbusiness2025@gmail.com');
    if (!email) return;
    toast('Sending test...');
    const data = await api('newsletter-send.php', 'POST', { newsletter_id: id, test_email: email });
    if (data.success) toast('Test email sent!');
    else toast(data.error || 'Test failed.', true);
}

function previewNewsletter(id) {
    document.getElementById('previewFrame').src = `${API_BASE}/newsletter-admin.php?preview=1&id=${id}`;
    document.getElementById('previewModal').classList.add('open');
}

function closePreview() {
    document.getElementById('previewModal').classList.remove('open');
    document.getElementById('previewFrame').src = '';
}

async function deleteNewsletter(id) {
    if (!confirm('Delete this newsletter and its send logs?')) return;
    const data = await api(`newsletter-manage.php?id=${id}`, 'DELETE');
    if (data.success) { toast('Newsletter deleted.'); loadNewsletters(); }
    else toast(data.error || 'Failed.', true);
}

// ── Utility ──
function esc(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}

// Close modal on overlay click
document.getElementById('previewModal').addEventListener('click', e => {
    if (e.target === e.currentTarget) closePreview();
});

// ══════════════════════════════════════
// AI CONTENT GENERATION
// ══════════════════════════════════════
async function aiGenerate() {
    const topic = document.getElementById('aiTopic').value.trim();
    if (!topic) { toast('Enter a topic first.', true); return; }

    const sections = [];
    if (document.getElementById('aiSecHotNews').checked) sections.push('hot_news');
    if (document.getElementById('aiSecInsight').checked) sections.push('insight');
    if (document.getElementById('aiSecExtra').checked) sections.push('extra');

    const toneNotes = document.getElementById('aiToneNotes').value.trim();
    const btn = document.getElementById('aiGenerateBtn');
    const status = document.getElementById('aiStatus');

    btn.disabled = true;
    btn.textContent = 'Generating...';
    status.textContent = 'Calling GPT-4o — this takes 10-20 seconds...';

    try {
        const data = await api('newsletter-ai-generate.php', 'POST', { topic, sections, tone_notes: toneNotes });

        if (!data.success) {
            toast(data.error || 'AI generation failed.', true);
            status.textContent = 'Failed: ' + (data.error || 'Unknown error');
            return;
        }

        const g = data.generated;

        // Fill compose form fields with generated content
        if (g.subject) document.getElementById('nlSubject').value = g.subject;
        if (g.hot_news_title) document.getElementById('nlHotTitle').value = g.hot_news_title;
        if (g.hot_news_body) document.getElementById('nlHotBody').value = g.hot_news_body;
        if (g.hot_news_link) document.getElementById('nlHotLink').value = g.hot_news_link;
        if (g.insight_text) document.getElementById('nlInsight').value = g.insight_text;
        if (g.extra_section_title) document.getElementById('nlExtraTitle').value = g.extra_section_title;
        if (g.extra_section_body) document.getElementById('nlExtraBody').value = g.extra_section_body;

        const tokens = data.usage ? ` (${data.usage.total_tokens} tokens)` : '';
        status.textContent = `Generated via ${data.model}${tokens}`;
        toast('Content generated! Review and edit below, then save.');
    } catch (e) {
        toast('AI request failed: ' + e.message, true);
        status.textContent = 'Error: ' + e.message;
    } finally {
        btn.disabled = false;
        btn.textContent = '★ Generate with AI';
    }
}

// ══════════════════════════════════════
// DATA SOURCES
// ══════════════════════════════════════
async function fetchData(source) {
    toast(`Fetching ${source} data...`);
    const data = await api('newsletter-data-fetcher.php', 'POST', { source });
    if (data.success) {
        toast('Data fetched successfully.');
        loadDataSources();
    } else {
        toast(data.error || 'Fetch failed.', true);
    }
}

async function loadDataSources() {
    loadNewsFeed();
    loadReports();
    loadGoogleConns();
}

async function loadNewsFeed() {
    const wrap = document.getElementById('newsFeedWrap');
    try {
        const res = await fetch(`${API_BASE}/newsletter-data-api.php?type=news`, { headers: { 'X-API-Key': API_KEY } });
        const data = await res.json();
        if (!data.success || !data.news || data.news.length === 0) {
            wrap.innerHTML = '<div class="empty-state"><p>No news cached yet. Click "Fetch All Sources" above.</p></div>';
            return;
        }
        let html = '<table><thead><tr><th>Headline</th><th>Source</th><th>Published</th></tr></thead><tbody>';
        data.news.forEach(n => {
            const pub = n.published_at ? new Date(n.published_at).toLocaleDateString() : '—';
            html += `<tr>
                <td><a href="${esc(n.url)}" target="_blank" style="color:var(--tiffany);font-size:12px;">${esc(n.title)}</a></td>
                <td style="font-size:11px;white-space:nowrap;">${esc(n.source || '—')}</td>
                <td style="font-size:11px;white-space:nowrap;">${pub}</td>
            </tr>`;
        });
        html += '</tbody></table>';
        wrap.innerHTML = html;
    } catch(e) { wrap.innerHTML = '<div class="empty-state"><p>Failed to load news feed.</p></div>'; }
}

async function loadReports() {
    const wrap = document.getElementById('reportsWrap');
    try {
        const res = await fetch(`${API_BASE}/newsletter-data-api.php?type=reports`, { headers: { 'X-API-Key': API_KEY } });
        const data = await res.json();
        if (!data.success || !data.reports || data.reports.length === 0) {
            wrap.innerHTML = '<div class="empty-state"><p>No website reports yet. Add client websites and run a PageSpeed fetch.</p></div>';
            return;
        }
        let html = '<table><thead><tr><th>Client</th><th>URL</th><th>Speed</th><th>SEO</th><th>Access.</th><th>Best Pr.</th><th>LCP</th><th>Scanned</th></tr></thead><tbody>';
        data.reports.forEach(r => {
            const sc = (v) => v >= 90 ? 'color:#55C98A' : v >= 50 ? 'color:#C9A84C' : 'color:#E05555';
            const lcp = (r.lcp_ms / 1000).toFixed(1) + 's';
            html += `<tr>
                <td style="color:#FFF;font-weight:500;font-size:12px;">${esc(r.client_name || '—')}</td>
                <td style="font-size:10px;"><a href="${esc(r.url)}" target="_blank" style="color:var(--tiffany);">${esc(r.url.replace(/^https?:\/\//, '').substring(0,25))}</a></td>
                <td style="${sc(r.performance_score)};font-weight:500;text-align:center;">${r.performance_score}</td>
                <td style="${sc(r.seo_score)};text-align:center;">${r.seo_score}</td>
                <td style="${sc(r.accessibility_score)};text-align:center;">${r.accessibility_score}</td>
                <td style="${sc(r.best_practices_score)};text-align:center;">${r.best_practices_score}</td>
                <td style="font-size:11px;text-align:center;">${lcp}</td>
                <td style="font-size:10px;white-space:nowrap;">${r.scanned_at || '—'}</td>
            </tr>`;
        });
        html += '</tbody></table>';
        wrap.innerHTML = html;
    } catch(e) { wrap.innerHTML = '<div class="empty-state"><p>Failed to load reports.</p></div>'; }
}

async function loadGoogleConns() {
    const wrap = document.getElementById('googleConnsWrap');
    try {
        const res = await fetch(`${API_BASE}/newsletter-data-api.php?type=google`, { headers: { 'X-API-Key': API_KEY } });
        const data = await res.json();
        if (!data.success) {
            wrap.innerHTML = `<div class="empty-state"><p>${esc(data.message || 'Google OAuth not configured yet.')}</p></div>`;
            return;
        }
        let html = '<table><thead><tr><th>Client</th><th>Location</th><th>Status</th><th>Last Metrics</th><th>Actions</th></tr></thead><tbody>';
        data.clients.forEach(c => {
            const connected = c.has_token;
            const badge = connected
                ? '<span class="badge badge-active">Connected</span>'
                : '<span class="badge badge-draft">Not Connected</span>';
            const metrics = c.latest_metrics
                ? `${c.latest_metrics.search_impressions + c.latest_metrics.map_impressions} impr, ${c.latest_metrics.website_clicks} clicks`
                : '—';
            const connectBtn = connected
                ? `<button class="btn btn-outline btn-sm" onclick="fetchData('gbp')">Refresh</button>`
                : `<button class="btn btn-gold btn-sm" onclick="connectGoogle(${c.id})">Connect</button>`;
            html += `<tr>
                <td style="color:#FFF;font-weight:500;">${esc(c.name)}</td>
                <td style="font-size:11px;">${esc(c.location_name || '—')}</td>
                <td>${badge}</td>
                <td style="font-size:11px;">${metrics}</td>
                <td>${connectBtn}</td>
            </tr>`;
        });
        html += '</tbody></table>';
        wrap.innerHTML = html;
    } catch(e) { wrap.innerHTML = '<div class="empty-state"><p>Failed to load Google connections.</p></div>'; }
}

function connectGoogle(clientId) {
    const scopes = 'https://www.googleapis.com/auth/business.manage';
    const authUrl = 'https://accounts.google.com/o/oauth2/v2/auth'
        + '?client_id=<?= GOOGLE_CLIENT_ID ?>'
        + '&redirect_uri=<?= urlencode(GOOGLE_REDIRECT_URI) ?>'
        + '&response_type=code'
        + '&scope=' + encodeURIComponent(scopes)
        + '&access_type=offline'
        + '&prompt=consent'
        + '&state=' + clientId;
    window.open(authUrl, '_blank', 'width=600,height=700');
}

// ══════════════════════════════════════
// CEO FUNNEL ANALYTICS
// ══════════════════════════════════════
let anaDays = 7;

// Period selector buttons
document.querySelectorAll('.ana-period').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.ana-period').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        // active style
        document.querySelectorAll('.ana-period').forEach(b => {
            b.style.borderColor = '';
            b.style.color = '';
        });
        btn.style.borderColor = 'var(--tiffany)';
        btn.style.color = 'var(--tiffany)';
        anaDays = parseInt(btn.dataset.days);
        loadAnalytics();
    });
});

async function loadAnalytics() {
    try {
        const [dashRes, visRes] = await Promise.all([
            fetch(`${API_BASE}/ceo-analytics.php?action=dashboard&days=${anaDays}`, { headers: { 'X-API-Key': API_KEY } }),
            fetch(`${API_BASE}/ceo-analytics.php?action=visitors&limit=50`, { headers: { 'X-API-Key': API_KEY } })
        ]);
        const dash = await dashRes.json();
        const vis = await visRes.json();

        if (!dash.success) {
            toast('Failed to load analytics: ' + (dash.error || 'Unknown'), true);
            return;
        }

        renderAnaStats(dash.totals);
        renderAnaDailyChart(dash.daily);
        renderAnaTable('anaPages', dash.pages, ['page','views','sessions'], ['Page','Views','Visitors']);
        renderAnaTable('anaReferrers', dash.referrers, ['referrer','views'], ['Referrer','Views']);
        renderAnaGeo(dash.geo);
        renderAnaDevices(dash.devices, dash.browsers);
        renderAnaScrolls(dash.scrolls);
        renderAnaClicks(dash.clicks);
        renderAnaDurations(dash.durations);
        renderAnaUtms(dash.utms);
        renderAnaVisitors(vis.visitors || []);
    } catch(e) {
        toast('Analytics load error: ' + e.message, true);
    }
}

function renderAnaStats(t) {
    document.getElementById('anaTotalViews').textContent = t.views.toLocaleString();
    document.getElementById('anaTotalSessions').textContent = t.sessions.toLocaleString();
    document.getElementById('anaTodayViews').textContent = t.today_views.toLocaleString();
    document.getElementById('anaTodaySessions').textContent = t.today_sessions.toLocaleString();
}

function renderAnaDailyChart(daily) {
    const el = document.getElementById('anaDailyChart');
    if (!daily || daily.length === 0) {
        el.innerHTML = '<span style="color:var(--text-muted);">No data yet for this period.</span>';
        return;
    }
    const maxViews = Math.max(...daily.map(d => d.views), 1);
    const barMax = 40; // max bar width in chars
    let html = '';
    daily.forEach(d => {
        const barLen = Math.max(1, Math.round((d.views / maxViews) * barMax));
        const bar = '<span style="color:var(--tiffany);">' + '█'.repeat(barLen) + '</span>';
        const dayLabel = d.day.slice(5); // MM-DD
        html += `<div style="display:flex;gap:10px;align-items:center;">
            <span style="min-width:46px;color:var(--text-muted);">${dayLabel}</span>
            ${bar}
            <span style="color:var(--text-dim);min-width:50px;">${d.views} views / ${d.sessions} visitors</span>
        </div>`;
    });
    el.innerHTML = html;
}

function renderAnaTable(elId, rows, keys, headers) {
    const el = document.getElementById(elId);
    if (!rows || rows.length === 0) {
        el.innerHTML = '<div style="color:var(--text-muted);font-size:12px;padding:12px 0;">No data yet.</div>';
        return;
    }
    let html = '<table><thead><tr>';
    headers.forEach(h => html += `<th>${h}</th>`);
    html += '</tr></thead><tbody>';
    rows.forEach(r => {
        html += '<tr>';
        keys.forEach((k, i) => {
            let val = r[k] || '—';
            if (k === 'page' || k === 'referrer') {
                val = esc(String(val).substring(0, 60));
            }
            const style = i === 0 ? 'color:#FFF;font-size:12px;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;' : 'text-align:right;';
            html += `<td style="${style}">${val}</td>`;
        });
        html += '</tr>';
    });
    html += '</tbody></table>';
    el.innerHTML = html;
}

function renderAnaGeo(geo) {
    const el = document.getElementById('anaGeo');
    if (!geo || geo.length === 0) {
        el.innerHTML = '<div style="color:var(--text-muted);font-size:12px;padding:12px 0;">No location data yet.</div>';
        return;
    }
    let html = '<table><thead><tr><th>Location</th><th>Views</th></tr></thead><tbody>';
    geo.forEach(g => {
        const loc = [g.city, g.region, g.country].filter(Boolean).join(', ');
        html += `<tr>
            <td style="color:#FFF;font-size:12px;">${esc(loc)}</td>
            <td style="text-align:right;">${g.views}</td>
        </tr>`;
    });
    html += '</tbody></table>';
    el.innerHTML = html;
}

function renderAnaDevices(devices, browsers) {
    const el = document.getElementById('anaDevices');
    let html = '<div style="margin-bottom:16px;">';
    html += '<div style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted);letter-spacing:2px;text-transform:uppercase;margin-bottom:8px;">Devices</div>';
    if (!devices || devices.length === 0) {
        html += '<span style="color:var(--text-muted);font-size:12px;">No data yet.</span>';
    } else {
        const total = devices.reduce((s, d) => s + d.views, 0);
        devices.forEach(d => {
            const pct = total ? Math.round((d.views / total) * 100) : 0;
            html += `<div style="display:flex;justify-content:space-between;padding:4px 0;font-size:12px;">
                <span style="color:var(--text-dim);">${esc(d.device || 'Unknown')}</span>
                <span style="color:var(--tiffany);">${pct}% <span style="color:var(--text-muted);">(${d.views})</span></span>
            </div>`;
        });
    }
    html += '</div><div>';
    html += '<div style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted);letter-spacing:2px;text-transform:uppercase;margin-bottom:8px;">Browsers</div>';
    if (!browsers || browsers.length === 0) {
        html += '<span style="color:var(--text-muted);font-size:12px;">No data yet.</span>';
    } else {
        const total = browsers.reduce((s, b) => s + b.views, 0);
        browsers.forEach(b => {
            const pct = total ? Math.round((b.views / total) * 100) : 0;
            html += `<div style="display:flex;justify-content:space-between;padding:4px 0;font-size:12px;">
                <span style="color:var(--text-dim);">${esc(b.browser || 'Unknown')}</span>
                <span style="color:var(--tiffany);">${pct}% <span style="color:var(--text-muted);">(${b.views})</span></span>
            </div>`;
        });
    }
    html += '</div>';
    el.innerHTML = html;
}

function renderAnaScrolls(scrolls) {
    const el = document.getElementById('anaScrolls');
    if (!scrolls || scrolls.length === 0) {
        el.innerHTML = '<div style="color:var(--text-muted);font-size:12px;padding:12px 0;">No scroll data yet.</div>';
        return;
    }
    let html = '';
    scrolls.forEach(s => {
        const pct = parseInt(s.avg_depth) || 0;
        const barW = Math.max(2, pct);
        const color = pct >= 75 ? 'var(--success)' : pct >= 50 ? 'var(--gold)' : pct >= 25 ? 'var(--tiffany)' : 'var(--danger)';
        html += `<div style="margin-bottom:10px;">
            <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:3px;">
                <span style="color:var(--text-dim);">${esc(s.page)}</span>
                <span style="color:${color};font-weight:500;">${pct}%</span>
            </div>
            <div style="background:var(--surface2);height:6px;border-radius:3px;overflow:hidden;">
                <div style="background:${color};width:${barW}%;height:100%;border-radius:3px;"></div>
            </div>
        </div>`;
    });
    el.innerHTML = html;
}

function renderAnaClicks(clicks) {
    const el = document.getElementById('anaClicks');
    if (!clicks || clicks.length === 0) {
        el.innerHTML = '<div style="color:var(--text-muted);font-size:12px;padding:12px 0;">No click data yet.</div>';
        return;
    }
    let html = '<table><thead><tr><th>Element</th><th>Link</th><th>Clicks</th></tr></thead><tbody>';
    clicks.forEach(c => {
        const label = esc((c.text || '').substring(0, 40));
        const href = c.href ? `<a href="${esc(c.href)}" target="_blank" style="color:var(--tiffany);font-size:10px;">${esc(c.href.substring(0, 30))}</a>` : '—';
        html += `<tr>
            <td style="color:#FFF;font-size:12px;">${label}</td>
            <td>${href}</td>
            <td style="text-align:right;color:var(--tiffany);">${c.clicks}</td>
        </tr>`;
    });
    html += '</tbody></table>';
    el.innerHTML = html;
}

function renderAnaDurations(durations) {
    const el = document.getElementById('anaDurations');
    if (!durations || durations.length === 0) {
        el.innerHTML = '<div style="color:var(--text-muted);font-size:12px;padding:12px 0;">No duration data yet.</div>';
        return;
    }
    let html = '<table><thead><tr><th>Page</th><th>Avg Time</th><th>Sessions</th></tr></thead><tbody>';
    durations.forEach(d => {
        const secs = parseInt(d.avg_seconds) || 0;
        const mins = Math.floor(secs / 60);
        const rem = secs % 60;
        const time = mins > 0 ? `${mins}m ${rem}s` : `${secs}s`;
        html += `<tr>
            <td style="color:#FFF;font-size:12px;max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(d.page)}</td>
            <td style="text-align:right;color:var(--gold);">${time}</td>
            <td style="text-align:right;">${d.sessions}</td>
        </tr>`;
    });
    html += '</tbody></table>';
    el.innerHTML = html;
}

function renderAnaUtms(utms) {
    const el = document.getElementById('anaUtms');
    if (!utms || utms.length === 0) {
        el.innerHTML = '<div style="color:var(--text-muted);font-size:12px;padding:12px 0;">No UTM data yet. Add ?utm_source=... to your links to track campaigns.</div>';
        return;
    }
    let html = '<table><thead><tr><th>Source</th><th>Medium</th><th>Campaign</th><th>Views</th></tr></thead><tbody>';
    utms.forEach(u => {
        html += `<tr>
            <td style="color:#FFF;font-size:12px;">${esc(u.utm_source)}</td>
            <td style="font-size:11px;">${esc(u.utm_medium || '—')}</td>
            <td style="font-size:11px;">${esc(u.utm_campaign || '—')}</td>
            <td style="text-align:right;color:var(--tiffany);">${u.views}</td>
        </tr>`;
    });
    html += '</tbody></table>';
    el.innerHTML = html;
}

function renderAnaVisitors(visitors) {
    const el = document.getElementById('anaVisitors');
    if (!visitors || visitors.length === 0) {
        el.innerHTML = '<div class="empty-state"><div class="empty-icon">&#9733;</div><p>No visitors recorded yet. The tracker will start collecting data once deployed.</p></div>';
        return;
    }
    let html = '<table><thead><tr><th>Time</th><th>Page</th><th>Location</th><th>Device</th><th>Browser</th><th>Referrer</th></tr></thead><tbody>';
    visitors.forEach(v => {
        const time = v.created_at ? v.created_at.slice(5, 16).replace('T',' ') : '—';
        const loc = [v.city, v.region, v.country].filter(x => x && x !== 'Unknown').join(', ') || '—';
        const ref = v.referrer && v.referrer !== 'Direct' && v.referrer !== ''
            ? esc(v.referrer.replace(/^https?:\/\//, '').substring(0, 30))
            : '<span style="color:var(--text-muted)">Direct</span>';
        html += `<tr>
            <td style="font-size:10px;white-space:nowrap;color:var(--text-dim);">${time}</td>
            <td style="color:#FFF;font-size:11px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(v.page)}</td>
            <td style="font-size:11px;">${esc(loc)}</td>
            <td style="font-size:11px;">${esc(v.device || '—')}</td>
            <td style="font-size:11px;">${esc(v.browser || '—')}</td>
            <td style="font-size:10px;">${ref}</td>
        </tr>`;
    });
    html += '</tbody></table>';
    el.innerHTML = html;
}

// Load initial data
loadClients();
</script>

</body>
</html>
