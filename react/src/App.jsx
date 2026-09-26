import { useState, useEffect, useCallback } from 'react'
import axios from 'axios'
import {
  BarChart, Bar, LineChart, Line,
  PieChart, Pie, Cell,
  XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer
} from 'recharts'
import './App.css'

// ─── Config ──────────────────────────────────────────────────────────────────
const API_URL = '/agrosmart/api/dashboard.php'

const GREEN_PALETTE = ['#2e7d32','#558b2f','#8bc34a','#c5e1a5','#33691e','#aed581']
const STATUS_COLORS  = {
  TM:  '#2e7d32', TBM: '#f57f17', TR:  '#b71c1c',
  HP:  '#1565c0', HPT: '#6a1b9a', LC:  '#795548',
}

// ─── Helpers ─────────────────────────────────────────────────────────────────
const fmt  = (n, dec = 0) => Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec })
const fmtK = (n) => n >= 1000 ? (n / 1000).toFixed(1) + 'k' : fmt(n)

// ─── Custom Tooltip ───────────────────────────────────────────────────────────
function CustomTooltip({ active, payload, label }) {
  if (!active || !payload?.length) return null
  return (
    <div className="custom-tooltip">
      <div className="label">{label}</div>
      {payload.map((p, i) => (
        <div className="row" key={i}>
          <span style={{ color: p.color }}>{p.name}</span>
          <span>{fmt(p.value, p.name?.includes('ton') || p.name?.includes('Area') ? 1 : 0)}</span>
        </div>
      ))}
    </div>
  )
}

// ─── KPI Card ─────────────────────────────────────────────────────────────────
function KpiCard({ label, value, unit, accent }) {
  return (
    <div className={`kpi-card ${accent || ''}`}>
      <div className="kpi-label">{label}</div>
      <div className="kpi-value">{fmtK(value)}</div>
      {unit && <div className="kpi-unit">{unit}</div>}
    </div>
  )
}

// ─── Main App ─────────────────────────────────────────────────────────────────
export default function App() {
  const [data, setData]       = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError]     = useState(null)

  const load = useCallback(() => {
    setLoading(true)
    setError(null)
    axios.get(API_URL)
      .then(res => { setData(res.data); setLoading(false) })
      .catch(err => { setError(err.message); setLoading(false) })
  }, [])

  useEffect(() => { load() }, [load])

  return (
    <div className="app">
      <nav className="navbar">
        <a className="navbar-brand" href="/agrosmart/">
          <span className="logo">🌴</span>
          <div>
            <div>AgroSmart</div>
            <div className="navbar-subtitle">Agribusiness Intelligence</div>
          </div>
        </a>
        <div className="navbar-right">
          <a href="/agrosmart/index.php" className="back-btn">
            ← Back to Dashboard
          </a>
          <span className="live-badge">🔴 Live</span>
        </div>
      </nav>

      <main className="main">
        {loading && <SkeletonDashboard />}

        {error && (
          <div className="error-screen">
            <div className="error-box">
              <strong>Could not load data</strong>
              <p style={{ marginTop: 8, fontSize: '0.85rem' }}>{error}</p>
              <button className="retry-btn" onClick={load}>Retry</button>
            </div>
          </div>
        )}

        {data && !loading && <Dashboard data={data} />}
      </main>

      <footer className="footer">
        AgroSmart – Agribusiness Intelligence &copy; {new Date().getFullYear()} &nbsp;·&nbsp; Powered by PHP &amp; PostgreSQL (Odoo) &nbsp;·&nbsp; Built with IBM Bob
      </footer>
    </div>
  )
}

// ─── Skeleton Loading UI ──────────────────────────────────────────────────────
function SkeletonCard({ wide }) {
  return <div className={`skeleton-card${wide ? ' full' : ''}`}><div className="skeleton-inner" /></div>
}

function SkeletonDashboard() {
  return (
    <>
      <div className="page-header">
        <div className="page-header-content">
          <div className="skeleton-line" style={{ width: 220, height: 22, marginBottom: 8 }} />
          <div className="skeleton-line" style={{ width: 300, height: 13 }} />
        </div>
      </div>
      <div className="main-inner">
        {/* KPI skeleton row */}
        <div className="kpi-grid">
          {Array.from({ length: 8 }).map((_, i) => (
            <div key={i} className="kpi-card skeleton-kpi">
              <div className="skeleton-line" style={{ width: '60%', height: 10, marginBottom: 10 }} />
              <div className="skeleton-line" style={{ width: '80%', height: 26 }} />
            </div>
          ))}
        </div>
        {/* Chart skeleton rows */}
        <div className="charts-grid">
          <SkeletonCard /><SkeletonCard />
        </div>
        <div className="charts-grid">
          <SkeletonCard wide />
        </div>
        <div className="charts-grid">
          <SkeletonCard /><SkeletonCard />
        </div>
      </div>
    </>
  )
}

// ─── Dashboard ────────────────────────────────────────────────────────────────
function Dashboard({ data }) {
  const { kpis, blocks_by_status, bu_by_type, planting_years, ffb_monthly, harvest_productivity } = data

  const tmPct  = kpis.total_area > 0 ? ((kpis.tm_area  / kpis.total_area) * 100).toFixed(1) : 0
  const tbmPct = kpis.total_area > 0 ? ((kpis.tbm_area / kpis.total_area) * 100).toFixed(1) : 0

  // Recharts needs numeric values
  const blocksPie = blocks_by_status.map(r => ({
    name: r.status,
    value: parseFloat(r.total_area),
    count: parseInt(r.block_count),
  }))

  const buBar = bu_by_type.map(r => ({
    name: r.unit_type,
    Units: parseInt(r.count),
    'Area (Ha)': parseFloat(r.total_area),
  }))

  const pyBar = [...planting_years].reverse().map(r => ({
    name: String(r.year),
    Blocks: parseInt(r.block_count),
    'Area (Ha)': parseFloat(r.total_area),
  }))

  const ffbLine = ffb_monthly.map(r => ({
    name: r.month,
    'FFB (ton)': parseFloat(r.weight_ton),
  }))

  const harvestLine = harvest_productivity.map(r => ({
    name: r.month,
    'Bunches': parseInt(r.bunches),
    'Weight (ton)': parseFloat(r.weight_ton),
  }))

  return (
    <>
      <div className="page-header">
        <div className="page-header-content">
          <h1>🌴 Plantation Dashboard</h1>
          <p>Real-time overview of AgroSmart plantation operations</p>
          <div className="last-updated">Last updated: {new Date(data.generated_at).toLocaleString()}</div>
        </div>
      </div>

      <div className="main-inner">

      {/* KPI Row */}
      <div className="kpi-grid">
        <KpiCard label="Companies"      value={kpis.companies}      accent="blue"    />
        <KpiCard label="Business Units" value={kpis.business_units} accent="green"   />
        <KpiCard label="Divisions"      value={kpis.divisions}      accent="teal"    />
        <KpiCard label="Blocks"         value={kpis.blocks}         accent="amber"   />
        <KpiCard label="Total Area"     value={kpis.total_area}     unit="Ha"        accent="indigo"  />
        <KpiCard label="TM (Mature)"    value={kpis.tm_area}        unit={`Ha · ${tmPct}%`}  accent="emerald" />
        <KpiCard label="TBM (Immature)" value={kpis.tbm_area}       unit={`Ha · ${tbmPct}%`} accent="orange"  />
        <KpiCard label="Total Plants"   value={kpis.total_plants}   accent="rose"    />
      </div>

      {/* Row 1: Blocks Pie + BU Bar */}
      <div className="charts-grid">
        <div className="chart-card">
          <div className="chart-title"><span className="icon">📊</span> Area by Block Status</div>
          <ResponsiveContainer width="100%" height={200}>
            <PieChart>
              <Pie data={blocksPie} dataKey="value" nameKey="name" cx="50%" cy="50%" outerRadius={90} label={({ name, value }) => `${name}: ${fmt(value, 1)} Ha`}>
                {blocksPie.map((entry) => (
                  <Cell key={entry.name} fill={STATUS_COLORS[entry.name] || '#888'} />
                ))}
              </Pie>
              <Tooltip formatter={(v) => `${fmt(v, 1)} Ha`} />
              <Legend />
            </PieChart>
          </ResponsiveContainer>
        </div>

        <div className="chart-card">
          <div className="chart-title"><span className="icon">🏢</span> Business Units by Type</div>
          <ResponsiveContainer width="100%" height={200}>
            <BarChart data={buBar} margin={{ top: 4, right: 16, left: 0, bottom: 4 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
              <XAxis dataKey="name" tick={{ fontSize: 12 }} />
              <YAxis yAxisId="left" tick={{ fontSize: 11 }} />
              <YAxis yAxisId="right" orientation="right" tick={{ fontSize: 11 }} />
              <Tooltip content={<CustomTooltip />} />
              <Legend />
              <Bar yAxisId="left" dataKey="Units" fill="#2e7d32" radius={[3,3,0,0]} />
              <Bar yAxisId="right" dataKey="Area (Ha)" fill="#8bc34a" radius={[3,3,0,0]} />
            </BarChart>
          </ResponsiveContainer>
        </div>
      </div>

      {/* Row 2: Planting Years full-width */}
      <div className="charts-grid">
        <div className="chart-card full">
          <div className="chart-title"><span className="icon">📅</span> Planting Years — Blocks &amp; Area</div>
          <ResponsiveContainer width="100%" height={180}>
            <BarChart data={pyBar} margin={{ top: 4, right: 16, left: 0, bottom: 4 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
              <XAxis dataKey="name" tick={{ fontSize: 12 }} />
              <YAxis yAxisId="left" tick={{ fontSize: 11 }} />
              <YAxis yAxisId="right" orientation="right" tick={{ fontSize: 11 }} />
              <Tooltip content={<CustomTooltip />} />
              <Legend />
              <Bar yAxisId="left" dataKey="Blocks" fill="#33691e" radius={[3,3,0,0]} />
              <Bar yAxisId="right" dataKey="Area (Ha)" fill="#aed581" radius={[3,3,0,0]} />
            </BarChart>
          </ResponsiveContainer>
        </div>
      </div>

      {/* Row 3: FFB + Harvest — only if data exists */}
      {(ffbLine.length > 0 || harvestLine.length > 0) && (
        <div className="charts-grid">
          {ffbLine.length > 0 && (
            <div className="chart-card">
              <div className="chart-title"><span className="icon">🚛</span> FFB Deliveries (last 12 months)</div>
              <ResponsiveContainer width="100%" height={180}>
                <LineChart data={ffbLine} margin={{ top: 4, right: 16, left: 0, bottom: 4 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                  <XAxis dataKey="name" tick={{ fontSize: 11 }} />
                  <YAxis tick={{ fontSize: 11 }} />
                  <Tooltip content={<CustomTooltip />} />
                  <Line type="monotone" dataKey="FFB (ton)" stroke="#2e7d32" strokeWidth={2} dot={{ r: 3 }} activeDot={{ r: 5 }} />
                </LineChart>
              </ResponsiveContainer>
            </div>
          )}

          {harvestLine.length > 0 && (
            <div className="chart-card">
              <div className="chart-title"><span className="icon">🌾</span> Harvest Productivity (last 6 months)</div>
              <ResponsiveContainer width="100%" height={180}>
                <LineChart data={harvestLine} margin={{ top: 4, right: 16, left: 0, bottom: 4 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                  <XAxis dataKey="name" tick={{ fontSize: 11 }} />
                  <YAxis yAxisId="left" tick={{ fontSize: 11 }} />
                  <YAxis yAxisId="right" orientation="right" tick={{ fontSize: 11 }} />
                  <Tooltip content={<CustomTooltip />} />
                  <Legend />
                  <Line yAxisId="left"  type="monotone" dataKey="Bunches"      stroke="#558b2f" strokeWidth={2} dot={{ r: 3 }} />
                  <Line yAxisId="right" type="monotone" dataKey="Weight (ton)" stroke="#8bc34a" strokeWidth={2} dot={{ r: 3 }} />
                </LineChart>
              </ResponsiveContainer>
            </div>
          )}
        </div>
      )}
      </div>{/* end main-inner */}
    </>
  )
}
