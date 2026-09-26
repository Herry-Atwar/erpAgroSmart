<?php
// Public page — no login required
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>erpAgroSmart — Hackathon Presentation</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:100%;height:100%;background:#111;font-family:-apple-system,"Segoe UI",system-ui,sans-serif;overflow:hidden}

/* ── Deck shell ── */
#deck{width:100%;height:100%;position:relative}
.slide{position:absolute;inset:0;display:none;flex-direction:column;justify-content:center;align-items:center;padding:0}
.slide.active{display:flex}

/* ── Slide canvas: fixed 16:9 aspect, centred, scaled ── */
.canvas{
  position:relative;
  width:960px;height:540px;
  flex-shrink:0;
  transform-origin:center center;
}

/* ── Shared slide chrome ── */
.top-bar{position:absolute;top:0;left:0;right:0;height:22px;background:#1b5e20;display:flex;align-items:center;padding:0 14px}
.top-bar .label{font-size:9px;font-weight:700;color:#8bc34a;letter-spacing:.8px;text-transform:uppercase}
.slide-num{position:absolute;top:3px;right:14px;font-size:9px;font-weight:700;color:#8bc34a}

/* ── Title slides (dark) ── */
.slide-dark .canvas{background:#1b5e20}
.slide-dark .hero-badge{display:inline-block;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.3);border-radius:20px;padding:3px 14px;font-size:11px;font-weight:700;color:#8bc34a;letter-spacing:.6px;text-transform:uppercase;margin-bottom:14px}
.slide-dark h1{font-size:60px;font-weight:800;color:#fff;text-align:center;line-height:1.05;margin-bottom:10px}
.slide-dark .subtitle{font-size:22px;color:#c8e6c9;text-align:center;margin-bottom:10px}
.slide-dark .tagline{font-size:13px;color:#a5d6a7;text-align:center;margin-bottom:6px}
.slide-dark .team{font-size:12px;color:#81c784;text-align:center}
.slide-dark .vision-text{font-size:13px;color:#a5d6a7;text-align:center;line-height:1.7;max-width:680px;margin:0 auto 14px}
.slide-dark .footer{font-size:11px;color:#81c784;text-align:center;position:absolute;bottom:18px;width:100%}

/* ── Content slides (white) ── */
.slide-light .canvas{background:#fff}
.slide-light h2{font-size:28px;font-weight:700;color:#1b5e20;position:absolute;top:32px;left:28px;right:28px}
.intro-text{position:absolute;top:80px;left:28px;right:28px;font-size:13px;color:#444}

/* Problem cards */
.problem-cards{position:absolute;top:130px;left:28px;right:28px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
.pc{padding:14px;border-radius:6px;font-size:11px;line-height:1.5}
.pc strong{display:block;font-size:12px;margin-bottom:6px}
.pc-orange{background:#fff3e0;color:#1f2328}
.pc-red{background:#ffebee;color:#1f2328}
.pc-green{background:#e8f5e9;color:#1f2328}
.stat-bar{position:absolute;bottom:18px;left:28px;right:28px;background:#e8f5e9;border-radius:4px;padding:8px 14px;font-size:11px;font-weight:700;color:#2e7d32;text-align:center}

/* Solution */
.hier-bar{position:absolute;top:120px;left:28px;right:28px;background:#2e7d32;color:#fff;border-radius:4px;padding:7px 14px;font-size:12px;font-weight:700;text-align:center}
.stack-bar{position:absolute;top:152px;left:28px;right:28px;font-size:11px;color:#1565c0;text-align:center}
.module-grid{position:absolute;top:180px;left:28px;right:28px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
.mod{padding:10px 12px;border-radius:5px;font-size:10px;line-height:1.5}
.mod strong{display:block;font-size:11px;margin-bottom:3px}
.mod-green{background:#f1f8e9;color:#1f2328}
.mod-indigo{background:#e8eaf6;color:#1f2328}
.mod-dark{background:#1b5e20;color:#fff}
.desc-text{position:absolute;top:80px;left:28px;right:28px;font-size:12px;color:#555}

/* GAPKI */
.sources-bar{position:absolute;top:80px;left:28px;right:28px;font-size:11px;color:#1565c0;text-align:center}
.std-grid{position:absolute;top:108px;left:28px;right:28px;display:grid;grid-template-columns:1fr 1fr;gap:10px}
.std-grid-3{position:absolute;bottom:18px;left:28px;right:28px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
.std{padding:10px 12px;border-radius:5px;font-size:10px;line-height:1.5}
.std strong{display:block;font-size:11px;margin-bottom:3px}
.std-green{background:#e8f5e9}
.std-yellow{background:#fff8e1}
.std-purple{background:#ede7f6}

/* Bob AI */
.score-grid{position:absolute;top:126px;left:28px;right:28px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;height:180px}
.sc{border-radius:6px;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:13px;line-height:1.5;padding:14px;text-align:center}
.sc-pass{background:#dcfce7;color:#166534}
.sc-warn{background:#fef3c7;color:#92400e}
.sc-fail{background:#fef2f2;color:#991b1b}
.sc strong{display:block;font-size:15px;font-weight:800;margin-bottom:8px}
.example-box{position:absolute;bottom:18px;left:28px;right:28px;background:#e3f2fd;border-radius:5px;padding:10px 14px;font-size:11px;color:#1565c0;line-height:1.5}

/* Demo flow */
.demo-grid{position:absolute;top:80px;left:28px;right:28px;display:grid;grid-template-columns:1fr 1fr;gap:10px}
.ds{padding:10px 14px;border-radius:5px;font-size:11px;line-height:1.5}
.ds strong{display:block;font-size:12px;margin-bottom:3px}
.ds-grey{background:#f8f9fa;color:#1f2328}
.ds-yellow{background:#fef3c7;color:#92400e}
.ds-green{background:#2e7d32;color:#fff}
.ds-blue{background:#e3f2fd;color:#1565c0;grid-column:1/-1;text-align:center}

/* Why we win */
.diff-grid{position:absolute;top:80px;left:28px;right:28px;display:grid;grid-template-columns:1fr 1fr;gap:10px}
.diff{padding:12px 14px;border-radius:5px;font-size:11px;line-height:1.5}
.diff strong{display:block;font-size:12px;margin-bottom:4px}
.diff-green{background:#e8f5e9;color:#1f2328}
.diff-blue{background:#e3f2fd;color:#1f2328}
.diff-dark{background:#1b5e20;color:#fff;grid-column:1/-1;text-align:center}

/* Inodesain */
.timeline{position:absolute;top:100px;left:28px;right:28px;display:flex;flex-direction:column;gap:8px}
.tl{padding:10px 16px;border-radius:5px;font-size:11px;line-height:1.5}
.tl strong{display:block;font-size:12px;margin-bottom:2px}
.tl-gold{background:#fff8e1;color:#78350f}
.tl-blue{background:#e3f2fd;color:#1e3a5f}
.tl-green{background:#2e7d32;color:#fff}
.vision-bottom{position:absolute;bottom:18px;left:28px;right:28px;font-size:13px;font-weight:700;color:#1b5e20;text-align:center}

/* ── Navigation ── */
#nav{position:fixed;bottom:0;left:0;right:0;height:44px;background:rgba(0,0,0,.7);display:flex;align-items:center;justify-content:center;gap:20px;z-index:100}
#nav button{background:none;border:1px solid rgba(255,255,255,.3);color:#fff;padding:5px 20px;border-radius:4px;cursor:pointer;font-size:13px}
#nav button:hover{background:rgba(255,255,255,.15)}
#counter{color:rgba(255,255,255,.6);font-size:12px;min-width:60px;text-align:center}
#hint{position:fixed;bottom:52px;right:16px;font-size:10px;color:rgba(255,255,255,.35)}

@media print{
  #nav,#hint{display:none}
  html,body{overflow:visible;background:#fff}
  #deck{height:auto}
  .slide{position:relative;display:flex!important;page-break-after:always;height:100vh}
}
</style>
</head>
<body>
<div id="deck">

  <!-- SLIDE 1: Title -->
  <div class="slide slide-dark active" id="s1">
    <div class="canvas" id="canvas">
      <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;padding:40px">
        <div class="hero-badge">IBM Bob Hackathon 2025</div>
        <h1>erpAgroSmart</h1>
        <div class="subtitle">AI-Powered Palm Oil Plantation Intelligence</div>
        <div class="tagline">GAPKI Compliant &nbsp;|&nbsp; Financially Transparent &nbsp;|&nbsp; Spatially Intelligent</div>
        <div class="team" style="margin-top:16px">Inodesain &nbsp;|&nbsp; IBM Business Partner &nbsp;|&nbsp; Indonesia</div>
      </div>
    </div>
  </div>

  <!-- SLIDE 2: The Problem -->
  <div class="slide slide-light" id="s2">
    <div class="canvas">
      <div class="top-bar"><span class="label">01 — The Problem</span></div>
      <h2>The Problem</h2>
      <div class="intro-text">Indonesia produces <strong>60% of the world's palm oil</strong>. Yet most plantation operations still suffer from three critical gaps:</div>
      <div class="problem-cards">
        <div class="pc pc-orange"><strong>1 &nbsp; FRAGMENTED DATA</strong>Production, cost, quality and agronomic data live in separate spreadsheets. No unified view, no single source of truth.</div>
        <div class="pc pc-red"><strong>2 &nbsp; NO STANDARDS BENCHMARK</strong>No automated way to compare actuals against GAPKI, PPKS, SNI, RSPO, or ISPO across 50+ parameters.</div>
        <div class="pc pc-green"><strong>3 &nbsp; SLOW DECISIONS</strong>Without an AI layer, identifying root causes of underperformance requires days of manual data analysis.</div>
      </div>
      <div class="stat-bar">60% of global palm oil supply comes from Indonesia — the stakes for getting this right could not be higher.</div>
    </div>
  </div>

  <!-- SLIDE 3: Solution -->
  <div class="slide slide-light" id="s3">
    <div class="canvas">
      <div class="top-bar"><span class="label">02 — The Solution</span></div>
      <h2>The Solution: erpAgroSmart Platform</h2>
      <div class="desc-text">A full-stack plantation ERP with IBM Bob AI embedded as a compliance and advisory engine — covering the complete operational chain from seedling to sale.</div>
      <div class="hier-bar">Company &nbsp;›&nbsp; Business Unit &nbsp;›&nbsp; Division (Afdeling) &nbsp;›&nbsp; Planting Year &nbsp;›&nbsp; Block</div>
      <div class="stack-bar">PHP 8+ &nbsp;|&nbsp; PostgreSQL &nbsp;|&nbsp; Bootstrap 5 &nbsp;|&nbsp; React (Vite) &nbsp;|&nbsp; <strong>IBM Bob AI</strong> &nbsp;|&nbsp; Leaflet.js GIS</div>
      <div class="module-grid">
        <div class="mod mod-green"><strong>Nursery</strong>Seedling stock · Production plan · Distribution</div>
        <div class="mod mod-green"><strong>Field Operations</strong>Work orders · Fertilization · Pest control</div>
        <div class="mod mod-green"><strong>Harvesting + Mill</strong>FFB · CPO/Kernel processing · OER · Quality</div>
        <div class="mod mod-indigo"><strong>Financial Reporting</strong>49 GL accounts · Double-entry · Budget variance</div>
        <div class="mod mod-indigo"><strong>GIS Block Map</strong>Leaflet.js · Compliance overlays · POI layers</div>
        <div class="mod mod-dark"><strong>IBM Bob AI</strong>GAPKI compliance scoring · Corrective actions</div>
      </div>
    </div>
  </div>

  <!-- SLIDE 4: GAPKI Standards -->
  <div class="slide slide-light" id="s4">
    <div class="canvas">
      <div class="top-bar"><span class="label">03 — GAPKI Standards</span></div>
      <h2>GAPKI Standards Library — 50+ Parameters</h2>
      <div class="sources-bar">GAPKI &nbsp;|&nbsp; PPKS Medan &nbsp;|&nbsp; SNI 8171:2015 &nbsp;|&nbsp; SNI 7182:2015 &nbsp;|&nbsp; Permentan RI &nbsp;|&nbsp; RSPO P&amp;C 2018 &nbsp;|&nbsp; ISPO 2020</div>
      <div class="std-grid">
        <div class="std std-green"><strong>PLANTATION</strong>SPH 136–148/ha · FFB yield ≥20 t/ha/yr<br>ABW 15–25 kg · Harvest interval 7–14 days</div>
        <div class="std std-green"><strong>MILL</strong>OER ≥22% · KER ≥4.5%<br>FFA &lt;3.5% · Moisture &lt;0.15%</div>
        <div class="std std-yellow"><strong>NURSERY</strong>Germination ≥80% · Survival ≥90%<br>Abnormal seedlings &lt;5%</div>
        <div class="std std-yellow"><strong>FERTILIZATION</strong>Urea 1.5–2.5 kg/palm/yr<br>Realization ≥90% · Frequency 2–4×/yr</div>
      </div>
      <div class="std-grid-3">
        <div class="std std-purple"><strong>FINANCE</strong>Production cost &lt;Rp800/kg FFB</div>
        <div class="std std-purple"><strong>SUSTAINABILITY</strong>Conservation ≥20% HGU · Riparian ≥50m</div>
        <div class="std std-purple"><strong>PEST &amp; DISEASE</strong>Ganoderma &lt;10% · Rat damage &lt;5%</div>
      </div>
    </div>
  </div>

  <!-- SLIDE 5: IBM Bob AI -->
  <div class="slide slide-light" id="s5">
    <div class="canvas">
      <div class="top-bar"><span class="label">04 — IBM Bob AI</span></div>
      <h2>IBM Bob AI — The Intelligence Layer</h2>
      <div class="desc-text">Bob transforms erpAgroSmart from a data recording system into a <strong>proactive advisory platform</strong> — automatically benchmarking every operational parameter and delivering corrective recommendations for every deviation.</div>
      <div class="score-grid">
        <div class="sc sc-pass"><strong>✓ PASS</strong>Actual meets or exceeds the GAPKI / SNI / PPKS standard</div>
        <div class="sc sc-warn"><strong>⚠ WARN</strong>Actual is in the warning zone — requires attention and monitoring</div>
        <div class="sc sc-fail"><strong>✗ FAIL</strong>Actual below minimum standard — Bob generates corrective recommendation</div>
      </div>
      <div class="example-box"><strong>Example Bob output:</strong> "Your OER of 20.5% is below the SNI 7182:2015 minimum of 22%. Check sterilisation temperature (140–145°C), press pressure (40–50 bar), and dilution water ratio. Inspect for unripe bunches at ramp — PPKS recommends ripeness fraction ≥65%."</div>
    </div>
  </div>

  <!-- SLIDE 6: Demo Flow -->
  <div class="slide slide-light" id="s6">
    <div class="canvas">
      <div class="top-bar"><span class="label">05 — Demo Flow</span></div>
      <h2>Live Demo Flow</h2>
      <div class="demo-grid">
        <div class="ds ds-grey"><strong>1 &nbsp; LOGIN</strong>Manager logs in — session scoped to company, role, and division.</div>
        <div class="ds ds-grey"><strong>2 &nbsp; DASHBOARD</strong>KPI overview — area, blocks, production YTD, mill OER, budget variance. 3D interactive charts.</div>
        <div class="ds ds-grey"><strong>3 &nbsp; LIVE DATA</strong>Harvesting Realizations (FFB actuals) · Mill Quality (OER, FFA, KER) · Financial Journal Entries.</div>
        <div class="ds ds-grey"><strong>4 &nbsp; GAPKI STANDARDS</strong>Browse 50+ parameter library — filterable by category, sourced from GAPKI/PPKS/SNI/RSPO/ISPO.</div>
        <div class="ds ds-yellow"><strong>5 &nbsp; COMPLIANCE ANALYZER ★</strong>Actual vs Standard scorecard — every WARN/FAIL highlighted with gap value and standard cited.</div>
        <div class="ds ds-green"><strong>6 &nbsp; BOB AI RECOMMENDATIONS ★</strong>For each failing parameter, Bob generates a standards-cited corrective action — live, on screen.</div>
        <div class="ds ds-blue"><strong>7 &nbsp; SMARTMAP</strong> — Click any block on the GIS map to instantly see compliance score, yield trend, and Bob recommendation.</div>
      </div>
    </div>
  </div>

  <!-- SLIDE 7: Why We Win -->
  <div class="slide slide-light" id="s7">
    <div class="canvas">
      <div class="top-bar"><span class="label">06 — Why We Win</span></div>
      <h2>Why erpAgroSmart Wins</h2>
      <div class="diff-grid">
        <div class="diff diff-green"><strong>Indonesian Standards Embedded</strong>Automatic compliance scoring against GAPKI, PPKS, SNI, RSPO, ISPO out of the box. No other open plantation ERP does this.</div>
        <div class="diff diff-green"><strong>Full Chain — No Silos</strong>Nursery to mill in one unified system. No data exports, no spreadsheet bridges, no manual reconciliation.</div>
        <div class="diff diff-blue"><strong>IBM Bob as Agronomy Advisor</strong>Cites the exact standard violated, quantifies the gap, and recommends specific interventions grounded in Indonesian palm oil best practice.</div>
        <div class="diff diff-blue"><strong>Enterprise Financial Reporting</strong>49 GL accounts, double-entry ledger, IDR 62.1B CPO/Kernel sales YTD, budget variance with missed-execution flags.</div>
        <div class="diff diff-dark"><strong>Integrated GIS Block Map</strong> — Every block spatially positioned with live compliance colour-coding. Click a block to instantly see GAPKI score and Bob recommendation.</div>
      </div>
    </div>
  </div>

  <!-- SLIDE 8: Inodesain -->
  <div class="slide slide-light" id="s8">
    <div class="canvas">
      <div class="top-bar"><span class="label">07 — About Inodesain</span></div>
      <h2>About Inodesain</h2>
      <div class="desc-text">Long-established IBM Business Partner with deep roots in the Indonesian agrobusiness sector — delivering enterprise-grade technology to plantation, mining, and forestry companies.</div>
      <div class="timeline">
        <div class="tl tl-gold"><strong>2016 — IBM Indonesia Linux Challenge</strong>2nd Place Winner — Professional Category. Competed against Indonesia's leading technology companies.</div>
        <div class="tl tl-blue"><strong>2018 — IBM Global Solution Directory</strong>Inodesain's agrobusiness platform accepted — confirming enterprise-grade quality for IBM's worldwide client network.</div>
        <div class="tl tl-green"><strong>2025 — IBM Bob Hackathon</strong>erpAgroSmart: a battle-tested plantation ERP, now supercharged with IBM Bob AI.</div>
      </div>
      <div class="vision-bottom">Not a new product — a proven platform, supercharged with AI.</div>
    </div>
  </div>

  <!-- SLIDE 9: Closing -->
  <div class="slide slide-dark" id="s9">
    <div class="canvas">
      <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;padding:48px">
        <h1 style="font-size:42px;margin-bottom:10px">erpAgroSmart + IBM Bob</h1>
        <div class="tagline" style="font-size:17px;margin-bottom:24px">GAPKI Compliant &nbsp;|&nbsp; Financially Transparent &nbsp;|&nbsp; Spatially Intelligent</div>
        <div class="vision-text">Indonesia produces 60% of the world's palm oil. Helping its plantation managers operate at GAPKI standard, with full financial transparency and spatially-aware operations — through intelligent, affordable, and locally-grounded software — is not just a product opportunity. It is a national competitiveness imperative.</div>
        <div class="footer">Built with IBM Bob &nbsp;|&nbsp; IBM Bob Hackathon 2025 &nbsp;|&nbsp; Inodesain — IBM Business Partner &nbsp;|&nbsp; inodesain.com</div>
      </div>
    </div>
  </div>

</div><!-- /deck -->

<!-- Navigation -->
<div id="nav">
  <button onclick="go(-1)">&#8592; Prev</button>
  <span id="counter">1 / 9</span>
  <button onclick="go(1)">Next &#8594;</button>
  <button onclick="window.print()" style="margin-left:20px;border-color:rgba(255,255,255,.15);font-size:11px;padding:4px 12px">&#128438; Print / PDF</button>
</div>
<div id="hint">&#8592; &#8594; arrow keys &nbsp;|&nbsp; Space = next</div>

<script>
const slides = document.querySelectorAll('.slide');
const canvas = document.querySelector('.canvas');
let cur = 0;

function scale() {
  const vw = window.innerWidth, vh = window.innerHeight - 44;
  const sc = Math.min(vw / 960, vh / 540);
  document.querySelectorAll('.canvas').forEach(c => {
    c.style.transform = `scale(${sc})`;
  });
}

function go(d) {
  slides[cur].classList.remove('active');
  cur = Math.max(0, Math.min(slides.length - 1, cur + d));
  slides[cur].classList.add('active');
  document.getElementById('counter').textContent = (cur + 1) + ' / ' + slides.length;
}

document.addEventListener('keydown', e => {
  if (e.key === 'ArrowRight' || e.key === 'ArrowDown' || e.key === ' ') { e.preventDefault(); go(1); }
  if (e.key === 'ArrowLeft'  || e.key === 'ArrowUp')                    { e.preventDefault(); go(-1); }
});

window.addEventListener('resize', scale);
scale();
</script>
</body>
</html>
