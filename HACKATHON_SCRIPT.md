# AgroSmart — IBM Bob Hackathon Video Narration Script
**Duration:** ~5 minutes | **Format:** Screen recording with voiceover
**Presenter:** Inodesain Team | **Language:** English (Bahasa Indonesia subtitle option)

---

## 🎬 INTRO — [0:00 – 0:30]

> *Camera on: AgroSmart login page or hero splash. Optional: aerial drone footage of a palm oil plantation.*

---

**NARRATOR:**

Indonesia is the world's largest producer of palm oil — supplying over 60% of global demand.
Yet behind those numbers, thousands of estate managers struggle with the same daily challenge:
fragmented data, no automated compliance check, and no AI layer to tell them what to fix.

Today, we introduce **AgroSmart** — an AI-powered plantation ERP built by Inodesain,
an IBM Business Partner with decades of agrobusiness expertise.

AgroSmart doesn't just record data. It benchmarks every metric against GAPKI, PPKS, SNI, RSPO,
and ISPO standards — automatically — and uses **IBM Bob AI** to tell you exactly what to do when
something falls short.

Let's take a look.

---

## 🔐 STEP 1 — Login & Session Scoping — [0:30 – 0:50]

> *Screen: Login page → dashboard redirect*

---

**NARRATOR:**

A manager logs in. Their session is automatically scoped to their company, business unit, and role.
No manual filter setup. The system knows who they are, where they work, and what they're allowed to see.

---

## 🏠 STEP 2 — Main Dashboard — [0:50 – 1:30]

> *Screen: `index.php` — 8 KPI cards, 3D bar chart, 3D donut, harvest productivity chart*

---

**NARRATOR:**

The dashboard opens with everything a plantation manager needs at a glance.

Eight compact KPI cards at the top — Total Area, Active Blocks, Workers, Harvest YTD,
Mill OER, CPO Stock, Budget Used, and Compliance Score — all live from the database.

Below them, three charts render in full 3D:

The **Monthly Harvest bar chart** — rotating 3D bars showing FFB tonnage across the last 12 months.
Notice the upward trend from January through April — that's real data from our plantation blocks.

The **Business Unit donut chart** — a 3D pie showing production split across business units.

The **Harvest Productivity line chart** — tracking bunches per hectare over time.

These are powered by **ECharts GL** — the same WebGL-accelerated 3D engine used by enterprise
analytics platforms — running locally, with no external CDN dependency.

On tablets and mobile, the system automatically detects if WebGL is unavailable and falls back
gracefully to 2D charts. The user never sees a blank screen.

---

## 📊 STEP 3 — Analytics Deep Dive — [1:30 – 2:00]

> *Screen: `analytics.php` — 5 ECharts panels with 2D/3D toggle buttons*

---

**NARRATOR:**

The Analytics module gives the full operational picture.

Five chart panels, each with a **2D / 3D toggle** — because 3D looks great in presentations,
but sometimes a clean 2D line chart is easier to read on screen.

We see:
- Harvest production trend by month
- Mill processing volume vs capacity
- Fertilization realization rate vs the PPKS minimum of 90%
- Cost per hectare vs the GAPKI budget benchmark
- Compliance score trend over time

Every chart pulls live from the database. No static mock data. No hardcoded numbers.

---

## 🗺️ STEP 4 — GIS Block Map — [2:00 – 2:30]

> *Screen: `blocks_map.php` — Leaflet.js map, block polygons, infrastructure overlays*

---

**NARRATOR:**

Here's something most plantation ERPs simply don't have: **a live GIS block map** built directly
into the operational system — not as a separate GIS tool, but as part of the same workflow.

Every block in the estate is rendered as a polygon, colour-coded by compliance status:
- **Green** — passing all GAPKI benchmarks
- **Amber** — warning zone, needs attention
- **Red** — failing one or more standards, immediate action required

Click any block and you instantly see its code, planting year, area in hectares, OER, FFB yield,
SPH, and GAPKI compliance score — all without leaving the map.

Infrastructure overlays show roads, bridges, warehouses, the nursery, and the mill —
all spatially positioned on the estate. Toggle them on and off as needed.

This is geospatial intelligence embedded inside an ERP. Not bolted on — built in.

---

## 📋 STEP 5 — GAPKI Standards Library — [2:30 – 2:50]

> *Screen: GAPKI Standards table — 50+ parameters, filterable by category*

---

**NARRATOR:**

At the core of AgroSmart is its **GAPKI Standards Library** — 50-plus parameters
across 10 categories: Plantation, Mill, Nursery, Infrastructure, Finance,
Fertilization, Weed Control, Pest & Disease, Agro Chemical, and Sustainability.

Every standard is sourced from an authoritative reference:
GAPKI, PPKS Medan 2020, SNI 7182:2015, SNI 8171:2015, RSPO P&C 2018, ISPO 2020.

This isn't a generic checklist. These are the specific numbers Indonesian estate managers
are held to by their industry body, certifiers, and government regulators.

And they're all machine-readable — ready to be compared against actual data, automatically.

---

## 🤖 STEP 6 — IBM Bob AI: GAPKI Compliance Analyzer — [2:50 – 3:45]

> *Screen: `gapki_compliance.php` — compliance scorecard, PASS/WARN/FAIL badges, Bob recommendations*

---

**NARRATOR:**

This is where **IBM Bob AI** changes everything.

The GAPKI Compliance Analyzer automatically compares every actual value in the system
against its corresponding standard — and scores it as PASS, WARN, or FAIL.

Let's look at a real example.

Our **Mill OER is 20.5%**. The SNI 7182:2015 minimum is 22%. That's a FAIL.

Our **Fertilization realization is 74%**. The PPKS minimum is 90%. That's also a FAIL.

For every failing parameter, Bob is called with a **structured compliance prompt**:

*"Fertilization realization is 74% vs PPKS minimum 90%. Explain the root causes and
 give 3 corrective actions grounded in PPKS Medan 2020 recommendations."*

Bob responds with a plain-language, standards-cited corrective recommendation —
telling the manager not just what's wrong, but exactly what to do about it.

This is not a chatbot. This is **AI-powered compliance advisory** — grounded in Indonesian
palm oil industry standards, integrated directly into the ERP workflow.

---

## 💰 STEP 7 — Financial Reporting — [3:45 – 4:10]

> *Screen: Financial module — Chart of Accounts, CPO sales ledger, budget variance*

---

**NARRATOR:**

Most plantation ERPs stop at operational data. AgroSmart goes further.

The Financial Reporting module delivers enterprise-grade accounting:

A full **Chart of Accounts** with 49 GL accounts across Asset, Liability, Equity, Revenue,
COGS, and Expense categories — with double-entry journal posting.

A **CPO and Kernel Sales Ledger** — tracking IDR 62 billion in transactions
to major buyers including Wilmar, Musim Mas, Sinar Mas, and Astra Agro.

And a **Budget Variance rollup** — 68 variance rows from 360 monthly execution records,
with 8 missed-execution gaps flagged for immediate management action.

This is the financial visibility that lets a plantation director walk into a board meeting
with confidence — not just on yield, but on cost, revenue, and profitability.

---

## 🏆 STEP 8 — Why AgroSmart Wins — [4:10 – 4:35]

> *Screen: hackathon_slides.php — Slide 7 "Why We Win" or the hackathon.php differentiators section*

---

**NARRATOR:**

Let's be direct about why AgroSmart stands out in this hackathon.

**One:** The standards engine is real. 50-plus GAPKI parameters, machine-readable,
sourced from the actual documents Indonesian estates are audited against.

**Two:** The IBM Bob integration is purpose-built — not a generic Q&A widget,
but a structured compliance advisory engine that interprets deviations in domain context.

**Three:** This is a production system, not a proof-of-concept. Inodesain has been
deploying plantation technology for years. AgroSmart is that experience,
elevated with AI.

**Four:** The GIS map, the 3D analytics, the financial module — these aren't wireframes.
They are working features, connected to a live PostgreSQL database on Supabase,
running right now.

---

## 🌟 CLOSING — [4:35 – 5:00]

> *Screen: AgroSmart hero image or hackathon closing slide. Soft plantation background.*

---

**NARRATOR:**

Indonesia's palm oil industry feeds the world.
The estates that power it deserve technology that is as serious as the work they do.

AgroSmart — built by Inodesain, powered by IBM Bob AI — gives every plantation manager
the compliance intelligence, financial clarity, and geospatial insight they need
to run a world-class estate.

This is **AgroSmart**.
Smarter Farming. Better Compliance. AI-Powered.

Thank you.

---

## 📋 Production Notes

| Segment | Screen | Duration | Key Visual |
|---|---|---|---|
| Intro | Login / Hero image | 0:00 – 0:30 | AgroSmart branding |
| Login | `index.php` redirect | 0:30 – 0:50 | Session scoping UX |
| Dashboard | `index.php` | 0:50 – 1:30 | 3D charts, 8 KPI cards |
| Analytics | `analytics.php` | 1:30 – 2:00 | 2D/3D toggle, 5 charts |
| GIS Map | `blocks_map.php` | 2:00 – 2:30 | Block polygons, colour coding |
| Standards | GAPKI standards table | 2:30 – 2:50 | 50+ parameter library |
| Bob AI | `gapki_compliance.php` | 2:50 – 3:45 | PASS/WARN/FAIL + Bob answer |
| Finance | Financial module | 3:45 – 4:10 | COA, sales ledger, variance |
| Why We Win | Differentiators / slide 7 | 4:10 – 4:35 | 5 differentiator cards |
| Closing | Hero / final slide | 4:35 – 5:00 | AgroSmart tagline |

### Recording Tips
- Use **1920×1080** screen recording minimum
- Browser zoom: **90%** to show more content per screen
- Pause 1 second on each chart before narrating — let WebGL render fully
- On the Bob AI screen, **type the prompt slowly** so viewers can read it
- Close all browser tabs except AgroSmart before recording
- Use a microphone, not laptop audio — plantation = serious enterprise product

### Bahasa Indonesia Subtitle Cues (key phrases)
| English | Bahasa Indonesia |
|---|---|
| Palm oil estate manager | Manajer kebun kelapa sawit |
| GAPKI compliance | Kepatuhan standar GAPKI |
| Corrective action | Tindakan perbaikan |
| Fresh Fruit Bunch (FFB) | Tandan Buah Segar (TBS) |
| Oil Extraction Rate (OER) | Rendemen Minyak |
| Fertilization realization | Realisasi pemupukan |
| Budget variance | Varians anggaran |
| Block map | Peta blok |
