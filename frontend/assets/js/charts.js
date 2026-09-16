/**
 * SAMS - Chart.js Visualizations Manager
 */

const Charts = {
  instances: {},

  getThemeColors() {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    return {
      textColor: isDark ? '#94a3b8' : '#64748b',
      gridColor: isDark ? 'rgba(255, 255, 255, 0.08)' : 'rgba(0, 0, 0, 0.06)'
    };
  },

  destroy(id) {
    if (this.instances[id]) {
      this.instances[id].destroy();
      delete this.instances[id];
    }
  },

  renderWeeklyAttendance(canvasId, labels, data) {
    this.destroy(canvasId);
    const ctx = document.getElementById(canvasId);
    if (!ctx || typeof Chart === 'undefined') return;

    const colors = this.getThemeColors();

    this.instances[canvasId] = new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [{
          label: 'Attendance %',
          data: data,
          borderColor: '#2563eb',
          backgroundColor: 'rgba(37, 99, 235, 0.1)',
          borderWidth: 3,
          fill: true,
          tension: 0.35,
          pointBackgroundColor: '#2563eb',
          pointRadius: 4,
          pointHoverRadius: 6
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: (item) => `Attendance: ${item.raw}%`
            }
          }
        },
        scales: {
          y: {
            min: 50,
            max: 100,
            ticks: {
              color: colors.textColor,
              callback: (v) => `${v}%`,
              stepSize: 10
            },
            grid: { color: colors.gridColor }
          },
          x: {
            ticks: { color: colors.textColor },
            grid: { display: false }
          }
        }
      }
    });
  },

  renderDepartmentComparison(canvasId, labels, data) {
    this.destroy(canvasId);
    const ctx = document.getElementById(canvasId);
    if (!ctx || typeof Chart === 'undefined') return;

    const colors = this.getThemeColors();

    this.instances[canvasId] = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Avg Attendance %',
          data: data,
          backgroundColor: ['#2563eb', '#4f46e5', '#0ea5e9'],
          borderRadius: 6
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false }
        },
        scales: {
          y: {
            min: 0,
            max: 100,
            ticks: {
              color: colors.textColor,
              callback: (v) => `${v}%`
            },
            grid: { color: colors.gridColor }
          },
          x: {
            ticks: { color: colors.textColor },
            grid: { display: false }
          }
        }
      }
    });
  },

  renderStatusDistribution(canvasId, labels, data) {
    this.destroy(canvasId);
    const ctx = document.getElementById(canvasId);
    if (!ctx || typeof Chart === 'undefined') return;

    const colors = this.getThemeColors();

    this.instances[canvasId] = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: labels,
        datasets: [{
          data: data,
          backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
          borderWidth: 0
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '72%',
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              boxWidth: 12,
              font: { size: 11 },
              color: colors.textColor
            }
          }
        }
      }
    });
  }
};

// Re-color charts on theme toggle
window.addEventListener('sams-theme-changed', () => {
  const colors = Charts.getThemeColors();
  Object.values(Charts.instances).forEach(chart => {
    if (chart && chart.options && chart.options.scales) {
      if (chart.options.scales.y) {
        if (chart.options.scales.y.ticks) chart.options.scales.y.ticks.color = colors.textColor;
        if (chart.options.scales.y.grid) chart.options.scales.y.grid.color = colors.gridColor;
      }
      if (chart.options.scales.x) {
        if (chart.options.scales.x.ticks) chart.options.scales.x.ticks.color = colors.textColor;
      }
      chart.update();
    }
  });
});
