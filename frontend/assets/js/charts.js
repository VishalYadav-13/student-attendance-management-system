/**
 * SAMS - Chart.js Visualizations Manager
 * Vintage Academic Theme Visualizations
 */

const Charts = {
  instances: {},

  getThemeColors() {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    return {
      textColor: isDark ? '#B1B8B3' : '#6F706A',
      gridColor: isDark ? 'rgba(52, 60, 56, 0.5)' : 'rgba(213, 204, 189, 0.4)'
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
          borderColor: '#6F7F6A',
          backgroundColor: 'rgba(111, 127, 106, 0.14)',
          borderWidth: 2.5,
          fill: true,
          tension: 0.3,
          pointBackgroundColor: '#6F7F6A',
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
          backgroundColor: ['#6F7F6A', '#6E7F8D', '#A56B52'],
          borderRadius: 4
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
          backgroundColor: ['#526B54', '#A56B52', '#9A4333', '#6E7F8D'],
          borderWidth: 0
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '70%',
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              boxWidth: 10,
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
