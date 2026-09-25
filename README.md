## Live demo  

https://inodesain.com/erpagrosmart

# erpAgroSmart

## AI-Powered Agrobusiness Solution

**IBM Bob Hackathon 2026 Submission**

**Integrated ERP · Indonesian Palm Oil Standards & Compliance · Financial Reporting · GIS**

---

## About Inodesain

**Inodesain** is a long-established IBM Business Partner with deep experience in Indonesian agrobusiness and plantation technology.

Our domain expertise includes:

* **Plantation Management** — nursery, field operations, harvesting, and mill operations
* **Forest & Land Management** — GIS mapping, kawasan, and land utilisation
* **Financial Reporting** — GL, double-entry accounting, CPO/Kernel sales, COGS, budget variance, and KPI dashboards
* **Mapping & GIS** — block maps, infrastructure POIs, compliance heatmaps, and spatial analytics
* **AI & Analytics** — intelligent insights for faster decision-making

### From Plantation ERP to AI Intelligence

erpAgroSmart is the evolution of years of real-world plantation ERP experience, enhanced with **IBM Bob AI** as its intelligence layer.

The platform combines operational data, financial information, spatial data, and Indonesian palm oil standards to turn plantation data into actionable compliance intelligence.

> **From raw plantation data to standards-grounded intelligence.**

---

## 1. Executive Summary

**erpAgroSmart** is a web-based ERP platform purpose-built for Indonesian palm oil plantation management.

It covers the operational chain from **nursery → field operations → harvesting → mill → inventory → financial reporting**, with GIS integration and an AI-powered compliance layer.

IBM Bob enables:

* Standards-based performance analysis
* Structured compliance Q&A
* Actual vs. standard comparison
* Deviation explanations
* Corrective action recommendations

The central question is simple:

> **"Is our plantation meeting Indonesian palm oil standards?"**

---

## 2. Problem

Palm oil plantation operations commonly face three challenges:

1. **Fragmented Data** — production, agronomy, cost, quality, and financial data are often maintained in separate systems or spreadsheets.
2. **No Integrated Standards Benchmark** — managers lack an automated way to compare operational performance against multiple Indonesian palm oil standards.
3. **Slow Decision-Making** — identifying the causes and corrective actions for underperformance requires manual analysis.

---

## 3. Solution

erpAgroSmart combines a complete plantation ERP with an **Indonesian Palm Oil Standards & Compliance Library** and **IBM Bob AI**.

### Plantation Hierarchy

The system follows a five-level plantation structure:

**Company → Business Unit → Division → Planting Year → Block**

This structure provides the foundation for operational, financial, and spatial analysis.

### Technology Stack

**PHP 8+ · MariaDB / PostgreSQL · Bootstrap 5 · React/Vite · Apache/XAMPP · Leaflet.js · IBM Bob AI**

---

## 4. Modules Built

The ERP foundation is already implemented across the following modules:

| Module                  | Key Features                                                                                    | Status              |
| ----------------------- | ----------------------------------------------------------------------------------------------- | ------------------- |
| **Master Data**         | Company, Business Unit, Division, Planting Year, Block, varieties, workers, partners            | **Ready**           |
| **Nursery**             | Seedling stock, production planning, distribution tracking                                      | **Ready**           |
| **Field Operations**    | Work orders, maintenance, fertilization, pest control                                           | **Ready**           |
| **Harvesting**          | Harvest plans, realization, productivity, quality control, FFB delivery                         | **Ready**           |
| **Mill Operations**     | CPO/Kernel processing, production, OER, FFA, KER, moisture                                      | **Ready**           |
| **Inventory**           | CPO, Kernel, and material management                                                            | **Ready**           |
| **Financial**           | GL, 49 accounts, double-entry journals, sales ledger, COGS, OpEx, depreciation, budget variance | **Ready**           |
| **Reports & Analytics** | KPI dashboard, cost analysis, profitability, budget variance, React dashboard                   | **Ready**           |
| **Standards Library**   | 50+ parameters across 10 categories                                                             | **Ready**           |
| **User Authentication** | Role-based login, session and company/division scoping                                          | **Ready**           |
| **Compliance Analyzer** | Actual vs. standard, Pass/Warn/Fail, IBM Bob recommendations                                    | **Hackathon Build** |

### Financial Data Demonstration

The current demonstration environment includes:

* **49 GL accounts**
* **42 posted journal entries**
* **IDR 62.1B CPO + Kernel sales YTD**
* **IDR 677.5M depreciation H1**
* **8 budget execution gaps flagged**
* **10 financial KPIs with monthly actuals vs. targets**

---

## 5. Indonesian Palm Oil Standards Library

erpAgroSmart includes a machine-readable standards library covering **50+ parameters across 10 categories**.

Reference sources include:

**GAPKI · PPKS Medan · SNI 8171:2015 · SNI 7182:2015 · Permentan RI · RSPO P&C 2018 · ISPO 2020**

### Standards Categories

| Category           | Example Parameters                                                                |
| ------------------ | --------------------------------------------------------------------------------- |
| **Plantation**     | SPH, plant population, TM ratio, FFB yield, ABW, harvest interval, harvest losses |
| **Mill**           | OER, KER, oil losses, FFA, moisture, capacity utilisation                         |
| **Nursery**        | Germination, survival rate, abnormal seedlings, seedling height, leaf count       |
| **Infrastructure** | Road density, road width, bridge density                                          |
| **Fertilization**  | Urea, TSP, MOP, Kieserite, realization, application frequency                     |
| **Weed Control**   | Piringan/gawangan condition, rotation, herbicide dose, labour norms               |
| **Finance**        | Production cost, maintenance cost                                                 |
| **Sustainability** | Conservation area, riparian buffer                                                |
| **Pest & Disease** | Ganoderma, rat damage, census frequency                                           |
| **Agro Chemical**  | Registered pesticides, PHI, PPE, calibration                                      |

The library stores standard definitions, thresholds, display formats, sources, and notes for automated analysis.

---

## 6. IBM Bob AI Integration

IBM Bob is the **intelligence layer** of erpAgroSmart.

For the Hackathon, the AI implementation focuses on **structured, standards-grounded compliance Q&A**.

### Example Questions

> "What is the current OER for our mill this month?"

> "Which blocks have below-standard SPH?"

> "Is our fertilizer realization meeting PPKS recommendations?"

> "What corrective actions should we take for blocks with harvest losses above 5%?"

### Compliance Analyzer

The Hackathon feature automatically compares actual plantation KPIs against the standards library:

**Actual Data → Standards Engine → Pass / Warn / Fail → IBM Bob → Explanation & Corrective Action**

For each deviation, IBM Bob receives structured context and produces a standards-grounded explanation and recommended corrective actions.

---

## 7. Financial Intelligence

Financial reporting is integrated with plantation operations.

Delivered capabilities include:

* General Ledger and Chart of Accounts
* Double-entry journal ledger
* CPO & Kernel sales
* COGS recognition
* Operating expense accruals
* Depreciation
* Budget vs. actual analysis
* KPI actuals vs. targets
* Profitability analysis

The objective is to connect **financial results back to plantation activities**, rather than treating accounting as a separate system.

---

## 8. GIS & Spatial Intelligence

erpAgroSmart integrates plantation operations with interactive GIS.

### Delivered

* Leaflet.js block compliance map
* Block-level compliance visualization
* Infrastructure POI overlays
* Roads, bridges, warehouses, nursery and mill locations
* Infrastructure density checks
* Click-to-inspect block information
* IBM Bob corrective recommendation panel
* Spatial compliance gap heatmap

This creates a spatial view of operational performance:

> **Every block can be inspected geographically together with its operational and compliance data.**

---

## 9. Hackathon Demo Flow

The demonstration follows an end-to-end workflow:

**1. Login**
Manager logs in with company, business unit, and role scope.

**2. Dashboard**
View plantation area, active blocks, production, OER, and financial KPIs.

**3. Operational Data**
Inspect harvesting, mill quality, fertilization, and other live records.

**4. Standards Library**
Review the 50+ standards and their sources.

**5. Compliance Analyzer**
Compare actual performance against standards.

**6. IBM Bob**
Explain detected gaps and generate corrective actions.

**7. GIS**
Locate compliance gaps spatially and inspect affected blocks.

---

## 10. What Makes erpAgroSmart Different

### 1. Standards Embedded in the ERP

The system does not only record plantation data; it incorporates Indonesian palm oil standards directly into the analysis layer.

### 2. Full Plantation Operational Chain

From **nursery to mill**, operational data is maintained within one integrated platform.

### 3. IBM Bob as an Intelligence Layer

Bob transforms detected deviations into understandable explanations and corrective recommendations using structured standards context.

### 4. Financial + Operational Integration

Financial information can be analysed together with plantation activities, production, costs, and KPIs.

### 5. Spatial Intelligence

Operational and compliance information can be viewed directly on the plantation map.

---

## 11. Hackathon Build & Roadmap

### Built / Enhanced for the Hackathon

* Indonesian Palm Oil Standards Compliance Analyzer
* IBM Bob compliance narration
* Structured compliance Q&A
* Financial reporting enhancements
* GIS/block compliance mapping
* Spatial gap analysis

### Next Development

**Cross-Module KPI Drill-Down**
Trace a compliance gap back to its underlying operational transactions.

**Trend Analysis**
Track actual vs. standard performance over time.

**Free-Form Natural Language Q&A**
Allow managers to ask questions directly against live operational data without predefined templates.

**Visual Pest & Disease Detection**
Photo-based identification, severity classification, and standards-grounded treatment recommendations.

**Visual FFB Grading**
AI-based ripeness and quality classification at the FFB ramp, linked to mill processing and OER analysis.

**Advanced AI / Vision**
Future integration with watsonx.ai, drone imagery, NDVI and on-premise computer vision for remote estates.

---

## 12. Vision

> **From reactive record-keeping to proactive plantation intelligence.**

Every block can have a live compliance view.
Every financial transaction can connect to plantation activity.
Every field image can become an AI-analyzed data point.
Every management question can become a standards-grounded insight.

**erpAgroSmart + IBM Bob**

**Standards-Aware · Financially Transparent · Spatially Intelligent**

---

## 13. About the Project

**Built with IBM Bob · IBM Bob Hackathon 2026 · erpAgroSmart v2.0**

**Inodesain — IBM Business Partner**

*AI-powered plantation intelligence for Indonesian palm oil.*

