// Chart builders for the KPI command center — thin wrappers over Chart.js
// (via primevue/chart) that resolve the app's theme tokens fresh each build so
// light/dark are honoured (they flip with [data-theme]). Mirrors the
// established pattern in FeedbackPanel.vue so every chart in the app reads as
// one system. Single-series charts carry no legend — the card title names them.

import { dzdToMil } from '@/features/payments/money'

export function chartTokens() {
  const s = getComputedStyle(document.documentElement)
  const v = (name, fallback) => s.getPropertyValue(name).trim() || fallback
  return {
    primary: v('--p-primary-500', '#c8a24a'),
    info: v('--app-info', '#3b82f6'),
    success: v('--app-success', '#16a34a'),
    warning: v('--app-warning', '#d97706'),
    danger: v('--app-danger', '#dc2626'),
    ink: v('--p-text-color', '#1f2937'),
    mute: v('--p-text-muted-color', '#6b7280'),
    line: v('--p-content-border-color', '#e5e7eb'),
    card: v('--p-content-background', '#ffffff'),
  }
}

// Categorical palette for multi-slice charts — brand gold first, then a
// distinguishable, colour-blind-considerate spread.
export function palette() {
  const c = chartTokens()
  return [c.primary, c.info, c.success, c.warning, c.danger, '#8b5cf6', '#0ea5e9', '#64748b']
}

// A ranked list → horizontal bar (magnitude by identity).
export function rankedBar(labels, values, color = 'primary') {
  const c = chartTokens()
  return {
    data: {
      labels,
      datasets: [
        {
          data: values,
          backgroundColor: c[color] ?? color,
          borderRadius: 4,
          borderSkipped: false,
          barThickness: 16,
          maxBarThickness: 22,
        },
      ],
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { beginAtZero: true, ticks: { color: c.mute, precision: 0 }, grid: { color: c.line } },
        y: { ticks: { color: c.ink }, grid: { display: false } },
      },
    },
  }
}

// A composition → doughnut. Colours WITH labels (never colour alone).
export function donut(labels, values, colors = null) {
  const c = chartTokens()
  return {
    data: {
      labels,
      datasets: [
        {
          data: values,
          backgroundColor: colors ?? palette(),
          borderColor: c.card,
          borderWidth: 2,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '62%',
      plugins: { legend: { position: 'bottom', labels: { color: c.ink, usePointStyle: true } } },
    },
  }
}

// A time-series → line. `series` is [{ label, points:[{date,value}], color, money }].
export function timeLine(series) {
  const c = chartTokens()
  // Union of all dates across series, sorted, so lines share one x-axis.
  const dates = [...new Set(series.flatMap((s) => s.points.map((p) => p.date)))].sort()
  const datasets = series.map((s) => {
    const byDate = Object.fromEntries(s.points.map((p) => [p.date, p.value]))
    return {
      label: s.label,
      data: dates.map((d) => {
        const raw = byDate[d]
        if (raw === undefined) return null
        return s.money ? dzdToMil(raw) : Number(raw)
      }),
      borderColor: c[s.color] ?? s.color ?? c.primary,
      backgroundColor: c[s.color] ?? s.color ?? c.primary,
      tension: 0.35,
      borderWidth: 2,
      pointRadius: 2,
      spanGaps: true,
    }
  })
  return {
    data: { labels: dates, datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom', labels: { color: c.ink, usePointStyle: true } } },
      scales: {
        x: { ticks: { color: c.mute, maxTicksLimit: 8 }, grid: { display: false } },
        y: { beginAtZero: true, ticks: { color: c.mute }, grid: { color: c.line } },
      },
    },
  }
}
