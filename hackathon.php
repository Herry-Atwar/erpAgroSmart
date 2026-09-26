<?php
// Public page — no login required for jury access
$page_title = "IBM Bob Hackathon Submission";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>erpAgroSmart — IBM Bob Hackathon Submission</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
<style>
  :root {
    --green-dark:  #1b5e20;
    --green-mid:   #2e7d32;
    --green-light: #e8f5e9;
    --amber:       #f59e0b;
    --red:         #dc2626;
    --ink:         #1f2328;
    --muted:       #57606a;
    --border:      #e5e7eb;
  }

  * { box-sizing: border-box; }

  body {
    font-family: -apple-system, "Segoe UI", system-ui, sans-serif;
    font-size: 14.5px;
    line-height: 1.65;
    color: var(--ink);
    background: #f4f6f0;
    margin: 0;
  }

  /* ── Hero ── */
  .hero {
    position: relative;
    color: #fff;
    text-align: center;
    overflow: hidden;
    min-height: 420px;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .hero-bg {
    position: absolute;
    inset: 0;
    background: url('/erpagrosmart/images/AgroSmart.jpg') center center / cover no-repeat;
    filter: brightness(0.38);
    z-index: 0;
  }
  .hero > div { position: relative; z-index: 1; padding: 56px 20px 48px; width: 100%; }
  .hero-badge {
    display: inline-block;
    background: rgba(255,255,255,0.18);
    border: 1px solid rgba(255,255,255,0.35);
    border-radius: 20px;
    padding: 4px 16px;
    font-size: 0.8rem;
    font-weight: 600;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    margin-bottom: 18px;
  }
  .hero h1 {
    font-size: clamp(1.8rem, 4vw, 2.8rem);
    font-weight: 800;
    margin: 0 0 10px;
    letter-spacing: -0.3px;
  }
  .hero .tagline {
    font-size: 1.05rem;
    opacity: 0.88;
    max-width: 620px;
    margin: 0 auto 24px;
  }
  .hero-meta {
    display: flex;
    justify-content: center;
    gap: 24px;
    flex-wrap: wrap;
    font-size: 0.85rem;
    opacity: 0.8;
  }
  .hero-meta span { display: flex; align-items: center; gap: 5px; }

  /* ── Layout ── */
  .page-wrap { max-width: 900px; margin: 0 auto; padding: 40px 20px 60px; }

  /* ── Section ── */
  .section { background: #fff; border-radius: 10px; border: 1px solid var(--border); padding: 32px 36px; margin-bottom: 24px; }
  .section-num {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px; border-radius: 50%;
    background: var(--green-mid); color: #fff;
    font-size: 0.78rem; font-weight: 700;
    margin-right: 10px; flex-shrink: 0;
  }
  .section-title {
    display: flex; align-items: center;
    font-size: 1.15rem; font-weight: 700;
    color: var(--green-dark);
    margin: 0 0 18px;
    padding-bottom: 12px;
    border-bottom: 2px solid var(--green-light);
  }
  .sub-title {
    font-size: 0.95rem; font-weight: 700;
    color: var(--green-mid);
    margin: 20px 0 8px;
  }

  /* ── Key value box ── */
  .kv-box {
    background: var(--green-light);
    border-left: 4px solid var(--green-mid);
    border-radius: 6px;
    padding: 14px 18px;
    margin: 14px 0;
    font-style: italic;
    color: var(--green-dark);
    font-weight: 500;
  }

  /* ── Problem cards ── */
  .problem-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px,1fr)); gap: 14px; margin-top: 14px; }
  .problem-card {
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 16px;
    background: #fafafa;
  }
  .problem-card .p-num {
    width: 24px; height: 24px; border-radius: 50%;
    background: #dc2626; color: #fff;
    font-size: 0.75rem; font-weight: 700;
    display: inline-flex; align-items: center; justify-content: center;
    margin-bottom: 8px;
  }
  .problem-card h6 { font-weight: 700; font-size: 0.9rem; margin: 0 0 5px; }
  .problem-card p  { font-size: 0.83rem; color: var(--muted); margin: 0; }

  /* ── Hierarchy ── */
  .hierarchy {
    display: flex; align-items: center; flex-wrap: wrap;
    gap: 0; margin: 14px 0;
  }
  .h-node {
    background: var(--green-dark); color: #fff;
    border-radius: 6px; padding: 6px 14px;
    font-size: 0.8rem; font-weight: 600;
  }
  .h-arrow { color: var(--green-mid); font-size: 1.1rem; padding: 0 6px; font-weight: 700; }

  /* ── Stack pills ── */
  .stack-pills { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
  .pill {
    background: var(--green-light); color: var(--green-dark);
    border: 1px solid #a5d6a7;
    border-radius: 20px; padding: 4px 14px;
    font-size: 0.8rem; font-weight: 600;
  }
  .pill.highlight { background: var(--green-dark); color: #fff; border-color: var(--green-dark); }

  /* ── Tables ── */
  .styled-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-top: 14px; }
  .styled-table thead tr th {
    background: var(--green-dark); color: #fff;
    padding: 10px 14px; text-align: left; font-weight: 600;
  }
  .styled-table tbody tr:nth-child(even) { background: #f9fafb; }
  .styled-table tbody tr:hover { background: var(--green-light); }
  .styled-table td { padding: 9px 14px; border-bottom: 1px solid var(--border); vertical-align: top; }
  .badge-complete  { background: #dcfce7; color: #166534; border-radius: 12px; padding: 2px 10px; font-size: 0.75rem; font-weight: 700; white-space: nowrap; }
  .badge-hackathon { background: #fef3c7; color: #92400e; border-radius: 12px; padding: 2px 10px; font-size: 0.75rem; font-weight: 700; white-space: nowrap; }

  /* ── Standards source pills ── */
  .src-pill {
    display: inline-block;
    background: #e0f2fe; color: #0369a1;
    border-radius: 10px; padding: 1px 8px;
    font-size: 0.73rem; font-weight: 600;
  }

  /* ── Compliance score ── */
  .compliance-row { display: grid; grid-template-columns: repeat(3,1fr); gap: 12px; margin-top: 14px; }
  .c-card {
    border-radius: 8px; padding: 16px 18px; text-align: center;
    border: 2px solid;
  }
  .c-card.pass  { border-color: #16a34a; background: #f0fdf4; color: #166534; }
  .c-card.warn  { border-color: var(--amber); background: #fffbeb; color: #92400e; }
  .c-card.fail  { border-color: var(--red);   background: #fef2f2; color: #991b1b; }
  .c-card .c-label { font-size: 1.2rem; font-weight: 800; margin-bottom: 4px; }
  .c-card .c-desc  { font-size: 0.78rem; }

  /* ── Bob Q&A examples ── */
  .qa-box {
    background: #f8f9fa; border-radius: 8px; padding: 14px 18px;
    border-left: 3px solid var(--green-mid); margin: 8px 0;
    font-size: 0.875rem; color: var(--muted); font-style: italic;
  }
  .qa-box::before { content: '"'; font-size: 1.2rem; color: var(--green-mid); font-style: normal; }
  .qa-box::after  { content: '"'; font-size: 1.2rem; color: var(--green-mid); font-style: normal; }

  /* ── Demo steps ── */
  .demo-steps { counter-reset: step; display: grid; gap: 10px; margin-top: 14px; }
  .demo-step {
    display: flex; align-items: flex-start; gap: 14px;
    background: #fafafa; border: 1px solid var(--border);
    border-radius: 8px; padding: 12px 16px;
  }
  .step-num {
    width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
    background: var(--green-mid); color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.8rem; font-weight: 700;
  }
  .step-content strong { display: block; font-size: 0.88rem; margin-bottom: 2px; }
  .step-content span   { font-size: 0.82rem; color: var(--muted); }

  /* ── Inodesain section ── */
  .inodesain-section {
    background: #fff;
    border-radius: 10px;
    border: 1px solid var(--border);
    padding: 32px 36px;
    margin-bottom: 24px;
  }
  .inodesain-header {
    display: flex; align-items: center; gap: 18px;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 2px solid var(--green-light);
  }
  .inodesain-logo {
    background: var(--green-dark); color: #fff;
    border-radius: 10px; padding: 10px 16px;
    font-size: 1.2rem; font-weight: 900;
    letter-spacing: 1px; white-space: nowrap;
    flex-shrink: 0;
  }
  .inodesain-logo span { color: #8bc34a; }
  .inodesain-tagline { font-size: 0.82rem; color: var(--muted); margin-top: 2px; }
  .ibm-bp-badge {
    display: inline-flex; align-items: center; gap: 6px;
    background: #1d4ed8; color: #fff;
    border-radius: 20px; padding: 4px 14px;
    font-size: 0.78rem; font-weight: 700;
    white-space: nowrap;
  }
  .timeline {
    display: flex; flex-direction: column; gap: 0;
    margin: 18px 0 0; border-left: 3px solid var(--green-light);
    padding-left: 20px;
  }
  .tl-item { position: relative; padding: 0 0 18px; }
  .tl-item::before {
    content: '';
    position: absolute; left: -26px; top: 5px;
    width: 10px; height: 10px;
    border-radius: 50%; background: var(--green-mid);
    border: 2px solid #fff;
    box-shadow: 0 0 0 2px var(--green-mid);
  }
  .tl-item .tl-year { font-size: 0.75rem; font-weight: 700; color: var(--green-mid); margin-bottom: 2px; }
  .tl-item .tl-text { font-size: 0.875rem; color: var(--ink); }
  .upgrade-box {
    background: linear-gradient(135deg, #1b5e20, #2e7d32);
    color: #fff; border-radius: 8px;
    padding: 18px 22px; margin-top: 20px;
    display: flex; align-items: center; gap: 16px;
  }
  .upgrade-box i { font-size: 2rem; opacity: 0.85; flex-shrink: 0; }
  .upgrade-box p { margin: 0; font-size: 0.9rem; line-height: 1.6; }
  .upgrade-box strong { font-size: 1rem; display: block; margin-bottom: 4px; }

  /* ── Img banner ── */
  .img-banner {
    width: 100%; border-radius: 8px;
    margin: 18px 0 4px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.12);
  }
  .img-caption {
    text-align: center; font-size: 0.78rem;
    color: var(--muted); margin-bottom: 4px;
    font-style: italic;
  }

  /* ── Differentiators ── */
  .diff-grid { display: grid; gap: 14px; margin-top: 14px; }
  .diff-card {
    display: flex; gap: 14px; align-items: flex-start;
    background: #fafafa; border: 1px solid var(--border);
    border-radius: 8px; padding: 16px;
  }
  .diff-icon {
    width: 38px; height: 38px; flex-shrink: 0;
    background: var(--green-dark); color: #fff;
    border-radius: 8px; display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem;
  }
  .diff-card h6 { font-weight: 700; font-size: 0.9rem; margin: 0 0 4px; color: var(--green-dark); }
  .diff-card p  { font-size: 0.83rem; color: var(--muted); margin: 0; }

  /* ── Roadmap ── */
  .roadmap-list { list-style: none; padding: 0; margin: 14px 0 0; display: grid; gap: 10px; }
  .roadmap-list li {
    display: flex; align-items: flex-start; gap: 10px;
    background: #fefce8; border: 1px solid #fde68a;
    border-radius: 8px; padding: 12px 16px;
    font-size: 0.875rem;
  }
  .roadmap-list li i { color: var(--amber); font-size: 1rem; flex-shrink: 0; margin-top: 1px; }

  /* ── Tech arch bullets ── */
  .arch-list { list-style: none; padding: 0; margin: 10px 0 0; display: grid; gap: 8px; }
  .arch-list li {
    display: flex; gap: 10px; align-items: flex-start;
    font-size: 0.875rem;
  }
  .arch-list li i { color: var(--green-mid); flex-shrink: 0; margin-top: 3px; }

  /* ── Closing ── */
  .closing {
    background: linear-gradient(135deg, #1b5e20, #2e7d32);
    color: #fff; border-radius: 10px;
    padding: 36px 40px; text-align: center;
    margin-bottom: 24px;
  }
  .closing p { font-size: 1rem; line-height: 1.7; opacity: 0.92; margin: 0 0 12px; }
  .closing .tagline-final { font-size: 1.15rem; font-weight: 700; opacity: 1; }

  /* ── Footer ── */
  .doc-footer {
    text-align: center; font-size: 0.78rem; color: var(--muted);
    padding-top: 16px; border-top: 1px solid var(--border);
  }

  @media (max-width: 600px) {
    .section { padding: 20px 18px; }
    .compliance-row { grid-template-columns: 1fr; }
    .problem-grid { grid-template-columns: 1fr; }
    .hero { padding: 36px 16px 32px; }
  }
</style>
</head>
<body>

<div style="background:#2c2c2c; padding: 10px 20px;">
  <a href="index.php" style="color:#fbbf24; text-decoration:none; font-size:0.875rem; font-weight:600;">
    <i class="bi bi-arrow-left-circle-fill"></i> Back to Dashboard
  </a>
</div>

<div class="page-wrap">

  <!-- Image 1: erpAgroSmart Platform Overview -->
  <img src="/erpagrosmart/images/AgroSmart.jpg" class="img-banner" alt="erpAgroSmart — AI-Powered Agrobusiness Solution">
  <p class="img-caption">erpAgroSmart — AI-Powered Agrobusiness Solution</p>

  <!-- Inodesain — About the Team -->
  <div class="inodesain-section">
    <div class="inodesain-header">
      <div>
        <div class="inodesain-logo">Ino<span>desain</span></div>
        <div class="inodesain-tagline">Innovative Design for the World</div>
      </div>
      <div>
        <span class="ibm-bp-badge"><i class="bi bi-shield-check"></i> IBM Business Partner</span>
        <div style="font-size:0.82rem;color:var(--muted);margin-top:6px">Agrobusiness &amp; Enterprise Solutions</div>
      </div>
    </div>

    <p><strong>Inodesain</strong> is a long-established IBM Business Partner with deep roots in the Indonesian agrobusiness sector. For many years, Inodesain has delivered enterprise-grade technology solutions to plantation companies, helping them digitise operations, manage assets, and make data-driven decisions across some of Indonesia's largest palm oil and forestry estates.</p>

    <div class="timeline">
      <div class="tl-item">
        <div class="tl-year">Foundation</div>
        <div class="tl-text">Inodesain established as an IT solutions provider focused on agribusiness and natural resources — industries at the heart of Indonesia's economy.</div>
      </div>
      <div class="tl-item">
        <div class="tl-year">IBM Business Partnership</div>
        <div class="tl-text">Recognised as an IBM Business Partner, enabling Inodesain to combine IBM's enterprise technology platform with deep domain expertise in plantation management, GIS mapping, and operational ERP.</div>
      </div>
      <div class="tl-item">
        <div class="tl-year">2016 — IBM Indonesia Linux Challenge</div>
        <div class="tl-text"><i class="bi bi-trophy-fill me-1" style="color:#f59e0b"></i> <strong>2nd Place Winner — Professional Category.</strong> Inodesain competed against leading Indonesian technology companies and was recognised as one of the winners in the Professional Category, demonstrating deep technical excellence on the IBM platform.</div>
      </div>
      <div class="tl-item">
        <div class="tl-year">2018 — IBM Global Solution Directory</div>
        <div class="tl-text"><i class="bi bi-globe me-1" style="color:#1d4ed8"></i> <strong>Listed on IBM Global Solution Directory.</strong> Inodesain's agrobusiness platform was accepted into IBM's Global Solution Directory — a mark of quality confirming the solution meets IBM's enterprise standards and is available to IBM's worldwide client network.</div>
      </div>
      <div class="tl-item">
        <div class="tl-year">Agrobusiness Expertise</div>
        <div class="tl-text">Built and deployed integrated solutions across plantation management, forest &amp; land management, mining operations, financial &amp; budget monitoring, and smart geospatial mapping for major Indonesian agrobusiness clients.</div>
      </div>
      <div class="tl-item">
        <div class="tl-year">erpAgroSmart — IBM Bob Hackathon</div>
        <div class="tl-text">Leveraging years of agrobusiness domain knowledge and the IBM Bob AI platform, Inodesain presents <strong>erpAgroSmart</strong> — the next evolution: a fully AI-powered ERP with embedded Standards compliance intelligence.</div>
      </div>
    </div>

    <div class="upgrade-box">
      <i class="bi bi-rocket-takeoff"></i>
      <p>
        <strong>Now Upgrading with AI Capabilities</strong>
        Inodesain is taking its proven agrobusiness platform to the next level by embedding <strong>IBM Bob AI</strong> as the intelligence layer — enabling natural language operations, automatic Standards compliance scoring, and AI-driven corrective recommendations. This is not a new product; it is a battle-tested solution, supercharged with AI.
      </p>
    </div>
  </div>

  <!-- 1. Executive Summary -->
  <div class="section">
    <h2 class="section-title"><span class="section-num">1</span>Executive Summary</h2>
    <p>erpAgroSmart is a comprehensive web-based ERP system purpose-built for Indonesian palm oil plantation management. It covers the full operational chain — from nursery and field operations through to mill processing, inventory, and financial reporting — all within a single integrated platform.</p>
    <p>The system is powered by <strong>IBM Bob AI</strong>, which automatically benchmarks actual performance against GAPKI / PPKS / SNI / RSPO / ISPO standards and delivers intelligent compliance recommendations when deviations are detected.</p>
    <p>Beyond Standards compliance scoring, erpAgroSmart has been enhanced with two strategic capability domains: <strong>Advanced Financial Reporting</strong> — including a full Chart of Accounts, double-entry journal ledger, CPO/Kernel sales tracking, budget variance rollup, and depreciation — and <strong>Mapping &amp; GIS Integration</strong> — embedding geospatial block maps, infrastructure overlays, and spatial analytics directly into the operational ERP workflow.</p>
    <div class="kv-box">
      <i class="bi bi-lightbulb-fill me-2"></i><strong>Key Value Proposition:</strong> erpAgroSmart transforms raw plantation data into actionable compliance intelligence — answering the question every Indonesian estate manager needs answered daily: <em>"Is my plantation meeting Industry standards, financially sound, and spatially optimised?"</em>
    </div>
  </div>

  <!-- 2. Problem Statement -->
  <div class="section">
    <h2 class="section-title"><span class="section-num">2</span>Problem Statement</h2>
    <p>Indonesia is the world's largest palm oil producer, contributing over <strong>60% of global supply</strong>. Yet most plantation operations — particularly small and medium estates — suffer from three critical gaps:</p>
    <div class="problem-grid">
      <div class="problem-card">
        <div class="p-num">1</div>
        <h6>Fragmented Data</h6>
        <p>Production, cost, quality, and agronomic data live in separate spreadsheets with no unified view.</p>
      </div>
      <div class="problem-card">
        <div class="p-num">2</div>
        <h6>No Standards Benchmark</h6>
        <p>No automated way to compare actual performance against GAPKI, PPKS, SNI, RSPO, or ISPO benchmarks across 50+ parameters.</p>
      </div>
      <div class="problem-card">
        <div class="p-num">3</div>
        <h6>Slow Decision-Making</h6>
        <p>Without an AI layer, identifying root causes of underperformance requires days of manual analysis.</p>
      </div>
    </div>
  </div>

  <!-- 3. Solution -->
  <div class="section">
    <h2 class="section-title"><span class="section-num">3</span>Solution: erpAgroSmart Platform</h2>
    <p>erpAgroSmart solves all three gaps through a unified architecture: a full-stack plantation ERP with IBM Bob AI embedded as a compliance and advisory engine.</p>

    <div class="sub-title">3.1 System Architecture</div>
    <p>Built on a <strong>5-level organisational hierarchy</strong> that mirrors how Indonesian palm oil estates are actually structured:</p>
    <div class="hierarchy">
      <span class="h-node">Company</span>
      <span class="h-arrow">›</span>
      <span class="h-node">Business Unit</span>
      <span class="h-arrow">›</span>
      <span class="h-node">Division (Afdeling)</span>
      <span class="h-arrow">›</span>
      <span class="h-node">Planting Year</span>
      <span class="h-arrow">›</span>
      <span class="h-node">Block</span>
    </div>
    <img src="/erpagrosmart/images/Infrastructure_Agro.png" class="img-banner" alt="erpAgroSmart Infrastructure in Plantation — connecting blocks, mill, warehouses, roads and facilities">
    <p class="img-caption">Infrastructure in Plantation — Connecting People, Land and Resources across the full estate hierarchy</p>
    <div class="sub-title" style="margin-top:16px">Technology Stack</div>
    <div class="stack-pills">
      <span class="pill">PHP 8+</span>
      <span class="pill">MariaDB / PostgreSQL</span>
      <span class="pill">Bootstrap 5</span>
      <span class="pill">React (Vite)</span>
      <span class="pill">Apache / XAMPP</span>
      <span class="pill highlight"><i class="bi bi-cpu me-1"></i>IBM Bob AI</span>
    </div>

    <div class="sub-title" style="margin-top:22px">3.2 Modules Built</div>
    <img src="/erpagrosmart/images/Nursery.png" class="img-banner" alt="erpAgroSmart Nursery Module — seedling monitoring, growth tracking and distribution">
    <p class="img-caption">Nursery Module — Healthy Seedlings for a Sustainable Future, with real-time monitoring and AI analytics</p>
    <img src="/erpagrosmart/images/Fertilization.png" class="img-banner" alt="erpAgroSmart Fertilization Module — precision nutrient management for maximum palm oil yield">
    <p class="img-caption">Fertilization Module — Precision Nutrient Management for Maximum Palm Oil Yield, powered by PPKS standards</p>
    <img src="/erpagrosmart/images/Pest_Disease.png" class="img-banner" alt="erpAgroSmart Pest & Disease Control Module — early detection and integrated management for healthy palm oil estates">
    <p class="img-caption">Pest &amp; Disease Control Module — Early Detection and Integrated Management for Healthy Palm Oil Estates</p>
    <table class="styled-table">
      <thead><tr><th>Module</th><th>Key Features</th><th>Status</th></tr></thead>
      <tbody>
        <tr><td><strong>Master Data</strong></td><td>Companies, Business Units, Divisions, Planting Years, Blocks (5-level hierarchy), Plant Varieties, Workers, Partners</td><td><span class="badge-complete">✓ Ready</span></td></tr>
        <tr><td><strong>Nursery</strong></td><td>Seedling stock, Production planning, Distribution tracking</td><td><span class="badge-complete">✓ Ready</span></td></tr>
        <tr><td><strong>Field Operations</strong></td><td>Work orders, Maintenance, Fertilization, Pest control</td><td><span class="badge-complete">✓ Ready</span></td></tr>
        <tr><td><strong>Harvesting</strong></td><td>Harvest plans, Realizations, Productivity tracking, Quality control, FFB delivery</td><td><span class="badge-complete">✓ Ready</span></td></tr>
        <tr><td><strong>Mill Operations</strong></td><td>CPO/Kernel processing, Production records, Quality control (OER, FFA, KER, moisture)</td><td><span class="badge-complete">✓ Ready</span></td></tr>
        <tr><td><strong>Inventory</strong></td><td>CPO stock, Kernel stock, Materials management</td><td><span class="badge-complete">✓ Ready</span></td></tr>
        <tr><td><strong>Financial Reporting</strong></td><td>Chart of Accounts (49 GL accounts), double-entry journal entries, CPO &amp; Kernel sales ledger, COGS recognition, OpEx accruals, depreciation, budget variance rollup with missed-execution flags, KPI actuals vs targets</td><td><span class="badge-complete">✓ Ready</span></td></tr>
        <tr><td><strong>Reports &amp; Analytics</strong></td><td>KPI Dashboard (10 KPIs × monthly actuals + targets), Cost by block/activity, Budget variance with gap analysis, Profitability by product, React Dashboard</td><td><span class="badge-complete">✓ Ready</span></td></tr>
        <tr><td><strong>Mapping &amp; GIS</strong></td><td>Interactive block map with status overlays (SPH, OER, yield, compliance score), estate boundary layers, infrastructure POI (roads, bridges, warehouses), spatial gap heatmap, block-level drill-down from map click</td><td><span class="badge-hackathon">🚧 Hackathon Build</span></td></tr>
        <tr><td><strong>Industry Standards Library</strong></td><td>50+ parameters across 10 categories: Plantation, Mill, Nursery, Infrastructure, Finance, Fertilization, Weed Control, Pest &amp; Disease, Agro Chemical, Sustainability</td><td><span class="badge-complete">✓ Ready</span></td></tr>
        <tr><td><strong>Standards Compliance Analyzer</strong></td><td>Actual vs Standard dashboard — automatic pass/warn/fail scoring per parameter with IBM Bob AI recommendations</td><td><span class="badge-hackathon">🚧 Hackathon Build</span></td></tr>
        <tr><td><strong>User Authentication</strong></td><td>Role-based login (admin, manager, user), session management, company/division scoping</td><td><span class="badge-complete">✓ Ready</span></td></tr>
      </tbody>
    </table>
  </div>

  <!-- 4. Industry Standards -->
  <div class="section">
    <h2 class="section-title"><span class="section-num">4</span>Industry Standards Integration</h2>
    <p>At the core of erpAgroSmart's competitive advantage is its embedded <strong>Industry Standards Library</strong> — a comprehensive, machine-readable reference of Indonesian palm oil industry benchmarks drawn from six authoritative sources:</p>
    <div class="stack-pills" style="margin-bottom:16px">
      <span class="pill">GAPKI</span>
      <span class="pill">PPKS Medan</span>
      <span class="pill">SNI 8171:2015</span>
      <span class="pill">SNI 7182:2015</span>
      <span class="pill">Permentan RI</span>
      <span class="pill">RSPO P&amp;C 2018</span>
      <span class="pill">ISPO 2020</span>
    </div>

    <div class="sub-title">4.1 Standards Coverage — 50+ Parameters Across 10 Categories</div>
    <table class="styled-table">
      <thead><tr><th>Category</th><th>Key Parameters</th><th>Source</th></tr></thead>
      <tbody>
        <tr><td><strong>Plantation</strong></td><td>SPH 136–148/ha, Normal plant ratio ≥92%, Dead plant &lt;2%, TM ratio ≥70%, FFB yield ≥20 ton/ha/yr, ABW 15–25 kg, Harvest interval 7–14 days, Harvest losses &lt;2%</td><td><span class="src-pill">GAPKI / PPKS</span></td></tr>
        <tr><td><strong>Mill</strong></td><td>OER ≥22%, KER ≥4.5%, Oil losses &lt;1.65%, FFA &lt;3.5%, Moisture &lt;0.15%, Capacity utilisation 70–95%</td><td><span class="src-pill">SNI 7182:2015</span></td></tr>
        <tr><td><strong>Nursery</strong></td><td>Germination rate ≥80%, Pre/Main nursery survival ≥90%, Abnormal seedlings &lt;5%, Height at 9 months 100–150 cm, Leaf count ≥9</td><td><span class="src-pill">SNI 8171:2015</span></td></tr>
        <tr><td><strong>Infrastructure</strong></td><td>Production road density 100–150 m/ha, Main road 30–60 m/ha, Road width ≥6m (prod) / ≥9m (main), Bridge density 0.5–3/km</td><td><span class="src-pill">GAPKI / Ditjenbun</span></td></tr>
        <tr><td><strong>Fertilization</strong></td><td>Urea 1.5–2.5 kg/palm/yr, TSP 0.75–1.5, MOP 2.0–3.5, Kieserit 0.75–1.5, Realization ≥90%, Frequency 2–4×/year</td><td><span class="src-pill">PPKS Medan 2020</span></td></tr>
        <tr><td><strong>Weed Control</strong></td><td>Piringan clean ≥85%, Gawangan clean ≥80%, Rotation 60–120 days, Herbicide dose 1.5–3.0 L/ha</td><td><span class="src-pill">PPKS / GAPKI</span></td></tr>
        <tr><td><strong>Finance</strong></td><td>Production cost &lt;Rp800/kg FFB, Maintenance cost &lt;Rp8 juta/ha/yr</td><td><span class="src-pill">GAPKI Benchmark 2023</span></td></tr>
        <tr><td><strong>Sustainability</strong></td><td>Conservation area ≥20% of HGU, Riparian buffer ≥50m</td><td><span class="src-pill">ISPO 2020 / RSPO</span></td></tr>
        <tr><td><strong>Pest &amp; Disease</strong></td><td>Ganoderma infection &lt;10%, Rat damage &lt;5%, Census frequency ≥2×/year</td><td><span class="src-pill">PPKS / GAPKI</span></td></tr>
        <tr><td><strong>Agro Chemical</strong></td><td>Pesticide registered use only, PHI compliance, Operator PPE 100%, Calibration ≥2×/year</td><td><span class="src-pill">Permentan No.39/2017</span></td></tr>
      </tbody>
    </table>
    <img src="/erpagrosmart/images/Fertilization_Analysis.jpg" class="img-banner" alt="erpAgroSmart Fertilization Analysis">
  </div>

  <!-- 5. IBM Bob AI -->
  <div class="section">
    <h2 class="section-title"><span class="section-num">5</span>IBM Bob AI Integration</h2>
    <p>IBM Bob AI is the intelligence layer that transforms erpAgroSmart from a data recording system into a <strong>proactive advisory platform</strong>. For the Hackathon, Bob is integrated as a <strong>structured compliance Q&amp;A engine</strong> — delivering pre-defined, standards-grounded answers for every GAPKI gap detected. Free-form natural language Q&amp;A is planned as a future enhancement (see Section 10).</p>

    <div class="sub-title">5.1 Structured Industry Compliance Q&amp;A <span class="badge-hackathon ms-2">🚧 Hackathon Feature</span></div>
    <p>The Hackathon implementation uses <strong>templated Bob prompts</strong> — one per compliance parameter — that feed Bob with structured context (actual value, standard value, gap %, category) and return a consistent, standards-grounded corrective recommendation. The flagship output is the <strong>Standards Compliance Analyzer</strong> dashboard:</p>
    <div class="compliance-row">
      <div class="c-card pass">
        <div class="c-label">✓ PASS</div>
        <div class="c-desc">Actual meets or exceeds the GAPKI / SNI / PPKS standard</div>
      </div>
      <div class="c-card warn">
        <div class="c-label">⚠ WARN</div>
        <div class="c-desc">Actual is in the warning zone — requires attention</div>
      </div>
      <div class="c-card fail">
        <div class="c-label">✗ FAIL</div>
        <div class="c-desc">Actual is below minimum standard — immediate action required</div>
      </div>
    </div>
    <p style="margin-top:14px">For every WARN or FAIL parameter, Bob is called with a <strong>structured prompt template</strong> (e.g. <em>"Fertilization realization is 74% vs PPKS minimum 90%. Explain the gap and give 3 corrective actions."</em>) — producing consistent, auditable recommendations grounded in the specific standard violated. This is distinct from open-ended Q&amp;A and requires no model fine-tuning.</p>
  </div>

  <!-- 6. Technical Architecture -->
  <div class="section">
    <h2 class="section-title"><span class="section-num">6</span>Technical Architecture</h2>
    <ul class="arch-list">
      <li><i class="bi bi-server"></i><div><strong>Backend:</strong> PHP 8+, Apache, XAMPP — pure PHP architecture with no framework overhead, enabling fast deployment on any estate office server.</div></li>
      <li><i class="bi bi-database"></i><div><strong>Database:</strong> MariaDB / PostgreSQL — full relational schema with 40+ tables, referential integrity, stored procedures, triggers, and computed views.</div></li>
      <li><i class="bi bi-palette"></i><div><strong>Frontend:</strong> Bootstrap 5 + Bootstrap Icons for the main ERP interface. React (Vite) for the interactive analytics dashboard.</div></li>
      <li><i class="bi bi-file-code"></i><div><strong>Standards Engine:</strong> <code>config/standards.php</code> — a pure PHP constants file defining 50+ standards with <code>pass_min</code>, <code>pass_max</code>, <code>warn_min</code>, <code>warn_max</code>, <code>display</code>, <code>source</code>, and <code>notes</code> per parameter. Zero database dependency — portable and maintainable.</div></li>
      <li><i class="bi bi-cpu"></i><div><strong>AI Layer:</strong> IBM Bob — accessed via the <code>agro_std_check()</code> function which returns pass/warn/fail status for any actual value against any standard, feeding Bob with structured context for compliance narration.</div></li>
    </ul>
  </div>

  <!-- 7. Demo Flow -->
  <div class="section">
    <h2 class="section-title"><span class="section-num">7</span>Hackathon Demo Flow</h2>
    <p>A complete end-to-end journey from raw data to AI-powered compliance insight:</p>
    <div class="demo-steps">
      <div class="demo-step"><div class="step-num">1</div><div class="step-content"><strong>Login</strong><span>Manager logs in. Session scoped to their company, business unit, and role.</span></div></div>
      <div class="demo-step"><div class="step-num">2</div><div class="step-content"><strong>Dashboard</strong><span>Overview of total area, active blocks, production YTD, mill OER, and budget variance.</span></div></div>
      <div class="demo-step"><div class="step-num">3</div><div class="step-content"><strong>Live Data</strong><span>Navigate to Harvesting › Realizations to show actual FFB data. Navigate to Mill › Quality to show OER, FFA, KER actuals.</span></div></div>
      <div class="demo-step"><div class="step-num">4</div><div class="step-content"><strong>Industry Standards Library</strong><span>Show the 50+ parameter reference — filterable by category, sourced from GAPKI/PPKS/SNI/RSPO/ISPO.</span></div></div>
      <div class="demo-step"><div class="step-num">5</div><div class="step-content"><strong>Standards Compliance Analyzer <span class="badge-hackathon ms-1">Hackathon Feature</span></strong><span>Show the actual vs standard scorecard. Every WARN or FAIL parameter is highlighted in amber/red with the gap value displayed.</span></div></div>
      <div class="demo-step"><div class="step-num">6</div><div class="step-content"><strong>Bob Structured Compliance Q&amp;A <span class="badge-hackathon ms-1">Hackathon Feature</span></strong><span>For each failing parameter, Bob is triggered with a structured prompt — returning a standards-cited corrective recommendation. Demonstrates AI-powered compliance advisory without requiring free-form NLP.</span></div></div>
    </div>
  </div>

  <!-- 8. Competitive Differentiation -->
  <div class="section">
    <h2 class="section-title"><span class="section-num">8</span>Competitive Differentiation</h2>
    <div class="diff-grid">
      <div class="diff-card">
        <div class="diff-icon"><i class="bi bi-patch-check"></i></div>
        <div>
          <h6>Indonesian Industry Standards Embedded</h6>
          <p>Not just data collection, but automatic compliance scoring against GAPKI, PPKS, SNI, RSPO, and ISPO. No other open plantation ERP does this out of the box.</p>
        </div>
      </div>
      <div class="diff-card">
        <div class="diff-icon"><i class="bi bi-diagram-3"></i></div>
        <div>
          <h6>Full Operational Chain — No Silos</h6>
          <p>Covers nursery to mill, from seedling germination rates to CPO FFA quality, within a single unified system. No data silos, no manual exports.</p>
        </div>
      </div>
      <div class="diff-card">
        <div class="diff-icon"><i class="bi bi-cpu"></i></div>
        <div>
          <h6>IBM Bob as Agronomy Advisor</h6>
          <p>Bob does not just surface data; it interprets deviations, cites the relevant standard (e.g. <em>"Your OER of 20.5% is below the SNI 7182:2015 minimum of 22%"</em>), and recommends specific interventions grounded in Indonesian palm oil agronomy best practice.</p>
        </div>
      </div>
      <div class="diff-card">
        <div class="diff-icon"><i class="bi bi-cash-coin"></i></div>
        <div>
          <h6>Enterprise-Grade Financial Reporting</h6>
          <p>Full double-entry GL with 49 Chart of Accounts, monthly CPO/Kernel sales ledger (IDR 62.1 billion YTD), COGS recognition, depreciation, and budget variance with missed-execution flagging — delivering P&amp;L visibility that typical plantation ERPs lack entirely.</p>
        </div>
      </div>
      <div class="diff-card">
        <div class="diff-icon"><i class="bi bi-map-fill"></i></div>
        <div>
          <h6>Integrated GIS Block Mapping</h6>
          <p>Every block, road, warehouse, and compliance score is spatially positioned on an interactive estate map — enabling managers to click a red block on the map and immediately see its Standards score, yield trend, and Bob's corrective recommendation without leaving the GIS view.</p>
        </div>
      </div>
    </div>
  </div>

  <!-- 9. Hackathon Roadmap -->
  <div class="section">
    <h2 class="section-title"><span class="section-num">9</span>Hackathon Roadmap</h2>
    <p>The following features have been built or are being enhanced during the Hackathon:</p>
    <ul class="roadmap-list">
      <li><i class="bi bi-hammer"></i><div><strong>Standards Compliance Analyzer Dashboard</strong> — <code>gapki_compliance.php</code> joining all actuals against the standards library with pass/warn/fail visual scorecard per category.</div></li>
      <li><i class="bi bi-hammer"></i><div><strong>IBM Bob Compliance Narration</strong> — Bob AI integration on the compliance page, generating plain-language deviation explanations and corrective action recommendations in Bahasa Indonesia.</div></li>
      <li><i class="bi bi-hammer"></i><div><strong>Cross-Module KPI Drill-Down</strong> — Bob drills from a failing compliance parameter to source transaction records (e.g. from low OER to the specific mill batches causing it).</div></li>
      <li><i class="bi bi-hammer"></i><div><strong>Trend Analysis</strong> — Historical actual vs standard tracking showing whether compliance gaps are improving or deteriorating over time.</div></li>
      <li><i class="bi bi-hammer"></i><div><strong>Financial Reporting Suite</strong> — Full Chart of Accounts (49 GL accounts), double-entry journal ledger, CPO/Kernel sales transactions (IDR 62.1 billion YTD), COGS, depreciation, and budget variance rollup with 8 missed-execution gaps flagged for analysis.</div></li>
      <li><i class="bi bi-hammer"></i><div><strong>GIS Block Map Integration</strong> — Interactive Leaflet.js map overlaying all estate blocks with real-time Standards compliance colour-coding (green/amber/red), infrastructure POI layers (roads, warehouses, bridges), and click-to-inspect panel with Bob AI recommendations per block.</div></li>
    </ul>
  </div>

  <!-- 10. Future Development -->
  <div class="section">
    <h2 class="section-title"><span class="section-num">10</span>Future Development</h2>
    <p>Beyond the Hackathon, Inodesain has identified the following strategic enhancements to the erpAgroSmart platform:</p>

    <!-- 10.1 Financial Reporting Deep Dive -->
    <div class="sub-title"><i class="bi bi-receipt-cutoff me-2"></i>10.1 Advanced Financial Reporting</div>
    <p>erpAgroSmart's financial module has been elevated from basic journal entry recording to a full plantation-grade financial reporting engine. The current implementation delivers:</p>
    <div class="diff-grid" style="margin-top:10px; margin-bottom:20px">
      <div class="diff-card">
        <div class="diff-icon"><i class="bi bi-book-fill"></i></div>
        <div>
          <h6>General Ledger &amp; Chart of Accounts</h6>
          <p>49 GL accounts across 6 account types (Asset, Liability, Equity, Revenue, COGS, Expense), with full double-entry journal posting — enabling real P&amp;L and Balance Sheet generation per estate, business unit, or company.</p>
        </div>
      </div>
      <div class="diff-card">
        <div class="diff-icon"><i class="bi bi-currency-exchange"></i></div>
        <div>
          <h6>CPO &amp; Kernel Sales Ledger</h6>
          <p>Monthly CPO sales transactions Jan–Sep 2026 (IDR 59.2 billion, 5.3 million kg to PT Wilmar, Musim Mas, Sinar Mas, Astra Agro) and Kernel sales (IDR 2.9 billion) — all automatically posted to AR and Revenue GL accounts with COGS recognition at 52% CPO / 45% Kernel ratio.</p>
        </div>
      </div>
      <div class="diff-card">
        <div class="diff-icon"><i class="bi bi-graph-down-arrow"></i></div>
        <div>
          <h6>Budget Variance &amp; Gap Analysis</h6>
          <p>68 variance rows rolled up from 360 monthly budget execution records — with <strong>8 missed executions</strong> flagged (status: open) for immediate management escalation. Variance categories: under-execution (MAINT −IDR 51K, HARV −IDR 20K, PEST −IDR 4.3K) and over-budget (FERT +IDR 2.8K).</p>
        </div>
      </div>
      <div class="diff-card">
        <div class="diff-icon"><i class="bi bi-bar-chart-steps"></i></div>
        <div>
          <h6>KPI Financial Dashboard</h6>
          <p>10 KPIs tracked monthly against annual targets — including CPO Revenue (IDR 38.5B annual target), OPEX/ha (IDR 8,500/ha), and Budget Achievement Rate (90% target). Actuals Jan–Sep 2026 seeded with realistic dips for gap analysis: April (76%) and June (85%) below threshold.</p>
        </div>
      </div>
    </div>
    <p><strong>Roadmap:</strong> Next phases include automated P&amp;L statement generation, cost-per-kg-FFB trending, inter-company eliminations for group reporting, and SAP/Odoo integration via journal entry synchronisation already scaffolded in the schema (<code>sap_document_number</code>, <code>odoo_source_id</code>).</p>

    <!-- 10.2 GIS Mapping Integration -->
    <div class="sub-title" style="margin-top:24px"><i class="bi bi-map-fill me-2"></i>10.2 Mapping &amp; GIS Integration</div>
    <p>erpAgroSmart integrates geospatial intelligence directly into plantation operations — moving beyond tabular reports to a live spatial view of the entire estate where every block, road, facility, and compliance score is visible on an interactive map.</p>
    <div class="diff-grid" style="margin-top:10px; margin-bottom:20px">
      <div class="diff-card">
        <div class="diff-icon"><i class="bi bi-map"></i></div>
        <div>
          <h6>Interactive Block Compliance Map</h6>
          <p>Leaflet.js-powered estate map rendering every block polygon colour-coded by Standards compliance score — green (pass), amber (warn), red (fail). Managers see the spatial distribution of underperformance at a glance, without opening a single report.</p>
        </div>
      </div>
      <div class="diff-card">
        <div class="diff-icon"><i class="bi bi-pin-map-fill"></i></div>
        <div>
          <h6>Infrastructure POI Overlays</h6>
          <p>Estate infrastructure — main roads, production roads, bridges, warehouses (WH-MAIN, WH-FIELD-A/B, WH-CHEM, WH-WORKSHOP), nursery, and mill — rendered as interactive POI layers that can be toggled on/off. Road density and bridge count are automatically compared against Standards infrastructure standards (100–150 m/ha, 0.5–3 bridges/km).</p>
        </div>
      </div>
      <div class="diff-card">
        <div class="diff-icon"><i class="bi bi-cursor-fill"></i></div>
        <div>
          <h6>Click-to-Inspect + Bob AI Panel</h6>
          <p>Clicking any block on the map opens an instant side panel showing: block code, planting year, area (ha), OER, FFB yield, SPH, Standards score, and IBM Bob's top corrective recommendation — all without navigating away from the map.</p>
        </div>
      </div>
      <div class="diff-card">
        <div class="diff-icon"><i class="bi bi-thermometer-half"></i></div>
        <div>
          <h6>Spatial Gap Heatmap</h6>
          <p>A weighted heatmap overlay visualising operational gaps (missed harvests, delayed fertilization, pest outbreaks) by geographic cluster — enabling estate managers and agronomists to identify spatial patterns that tabular reports cannot reveal, e.g. an entire afdeling underperforming due to road access issues.</p>
        </div>
      </div>
    </div>
    <p><strong>Roadmap:</strong> Future phases include GPS track logging for field worker movements, drone imagery ingestion with block-level annotation, NDVI (vegetation health) raster overlays from satellite imagery, and integration with Indonesia's BIG (Badan Informasi Geospasial) basemap services.</p>

    <!-- Visual Pest & Disease Detection -->
    <div class="sub-title" style="margin-top:24px"><i class="bi bi-bug-fill me-2"></i>10.3 Visual Pest &amp; Disease Detection System</div>
    <p>erpAgroSmart's Pest &amp; Disease Control module is currently record-based. The next evolution is an <strong>AI-powered visual detection layer</strong> — enabling field workers and drones to submit photos directly into the system for automatic pest identification, severity classification, and PPKS-grounded treatment recommendations.</p>
    <div class="diff-grid" style="margin-top:10px; margin-bottom:20px">
      <div class="diff-card">
        <div class="diff-icon"><i class="bi bi-phone-fill"></i></div>
        <div>
          <h6>Phase 1 — Photo Upload + IBM Bob Analysis</h6>
          <p>Field workers upload block photos via erpAgroSmart. IBM Bob identifies the pest or disease, estimates severity, and recommends treatment in Bahasa Indonesia — grounded in PPKS/GAPKI standards.</p>
        </div>
      </div>
      <div class="diff-card">
        <div class="diff-icon"><i class="bi bi-cpu-fill"></i></div>
        <div>
          <h6>Phase 2 — watsonx.ai Image Classification</h6>
          <p>Integration with <strong>IBM watsonx.ai</strong> trained on Indonesian palm oil pest taxonomy (Ganoderma, Bagworm, Rat damage, Leaf Blight, etc.) — auto-filling pest type, name, and severity upon photo submission.</p>
        </div>
      </div>
      <div class="diff-card">
        <div class="diff-icon"><i class="bi bi-camera-video-fill"></i></div>
        <div>
          <h6>Phase 3 — Drone &amp; On-Premise IoT</h6>
          <p>On-premise YOLOv8 model deployed alongside erpAgroSmart for remote estates with no connectivity — processing drone imagery locally with full data sovereignty across Indonesian plantation sites.</p>
        </div>
      </div>
    </div>

    <!-- Visual FFB Grading -->
    <div class="sub-title" style="margin-top:24px"><i class="bi bi-basket-fill me-2"></i>10.4 Visual FFB (Fresh Fruit Bunch) Grading System</div>
    <p>FFB quality grading is currently done manually at the ramp — a time-consuming, subjective process prone to human error. AI-powered visual grading replaces manual inspection with <strong>instant, objective, camera-based quality scoring</strong> at the point of delivery.</p>
    <div class="diff-grid" style="margin-top:10px; margin-bottom:20px">
      <div class="diff-card">
        <div class="diff-icon"><i class="bi bi-camera-fill"></i></div>
        <div>
          <h6>Automatic Ripeness Classification</h6>
          <p>Camera at the FFB ramp captures bunch images. AI classifies each bunch as <strong>Unripe / Ripe / Overripe / Empty / Abnormal</strong> — directly mapping to PPKS grading standards (ABW 15–25 kg, ripeness fraction ≥65%).</p>
        </div>
      </div>
      <div class="diff-card">
        <div class="diff-icon"><i class="bi bi-bar-chart-fill"></i></div>
        <div>
          <h6>OER Prediction &amp; Mill Linkage</h6>
          <p>Grading results feed directly into erpAgroSmart's <strong>FFB Delivery</strong> and <strong>Mill Processing</strong> modules — enabling predictive OER calculation before bunches enter the press, reducing FFA and quality deviations.</p>
        </div>
      </div>
      <div class="diff-card">
        <div class="diff-icon"><i class="bi bi-patch-check-fill"></i></div>
        <div>
          <h6>Standards Compliance &amp; Bob Narration</h6>
          <p>IBM Bob automatically flags grading deviations against SNI 7182:2015 and PPKS harvest quality standards, and generates corrective harvesting instructions for the field team — closing the loop from mill back to block.</p>
        </div>
      </div>
    </div>

    <!-- Natural Language Q&A -->
    <div class="sub-title" style="margin-top:24px"><i class="bi bi-chat-dots-fill me-2"></i>10.5 Free-Form Natural Language Q&amp;A</div>
    <p>The Hackathon delivers <strong>structured compliance Q&amp;A</strong> via templated Bob prompts. The next step is enabling plantation managers to ask Bob any question in plain language against their live operational data — without pre-defined templates.</p>
    <div class="diff-grid" style="margin-top:10px; margin-bottom:20px">
      <div class="diff-card">
        <div class="diff-icon"><i class="bi bi-chat-left-text-fill"></i></div>
        <div>
          <h6>Example Future Queries</h6>
          <p><em>"Which blocks have below-standard SPH?"</em> &nbsp;·&nbsp; <em>"Is our fertilizer realization meeting PPKS recommendations?"</em> &nbsp;·&nbsp; <em>"What is the OER trend for our Riau mill this quarter?"</em></p>
        </div>
      </div>
      <div class="diff-card">
        <div class="diff-icon"><i class="bi bi-database-fill"></i></div>
        <div>
          <h6>What It Requires</h6>
          <p>Dynamic SQL generation from natural language, live database context injection into Bob prompts, multi-table query reasoning across the full erpAgroSmart schema — significant engineering effort beyond the Hackathon scope.</p>
        </div>
      </div>
    </div>

    <div class="kv-box">
      <i class="bi bi-binoculars-fill me-2"></i><strong>Vision:</strong> From reactive record-keeping to <em>proactive field, financial &amp; spatial intelligence</em> — where every block on the map shows its live compliance score, every journal entry traces to a plantation activity, every photo taken in the field instantly becomes an AI-analysed data point, and every manager question gets a grounded, standards-cited answer from IBM Bob.
    </div>
  </div>

  <!-- 11. Closing -->
  <div class="closing">
    <p>erpAgroSmart was built for and during the IBM Bob Hackathon as a real-world demonstration of what AI-powered ERP can deliver for the Indonesian palm oil sector — the backbone of the nation's agricultural economy.</p>
    <p>Indonesia produces <strong>60% of the world's palm oil</strong>. Helping its plantation managers operate at Industry standard, with full financial transparency and spatially-aware operations — through intelligent, affordable, and locally-grounded software — is not just a product opportunity. It is a national competitiveness imperative.</p>
    <p class="tagline-final">erpAgroSmart + IBM Bob: Standards Compliant · Financially Transparent · Spatially Intelligent.</p>
  </div>

  <div class="doc-footer">
    <p>Built with IBM Bob &nbsp;|&nbsp; IBM Bob Hackathon 2026 &nbsp;|&nbsp; erpAgroSmart v2.0</p>
  </div>

</div><!-- /page-wrap -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
