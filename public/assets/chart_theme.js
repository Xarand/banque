// Adapte Chart.js aux couleurs du thème (variables CSS exposées par theme.php)
(function () {
    function cssVar(name, fallback) {
      var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
      return v || fallback || '#0D6EFD';
    }
    function hexToRGBA(hex, alpha) {
      if (!/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(hex)) return 'rgba(13,110,253,' + (alpha ?? 1) + ')';
      let c = hex.substring(1);
      if (c.length === 3) c = c.split('').map(x => x + x).join('');
      const num = parseInt(c, 16);
      const r = (num >> 16) & 255, g = (num >> 8) & 255, b = num & 255;
      return 'rgba(' + r + ',' + g + ',' + (alpha ?? 1) + ')';
    }
  
    // Couleurs issues du thème
    var primary = cssVar('--primary', '#0D6EFD');
    var fg      = cssVar('--app-fg', '#212529');
    var grid    = cssVar('--app-border', '#DEE2E6');
    var cardBg  = cssVar('--card-bg', '#FFFFFF');
  
    // Palette des jeux de données
    var creditColor = primary;   // crédits
    var debitColor  = '#dc3545'; // débits
    var netColor    = '#6c757d'; // net
  
    window.addEventListener('DOMContentLoaded', function () {
      if (!window.Chart) return;
      // Defaults globaux
      Chart.defaults.color = fg;
      Chart.defaults.font.family = 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", "Liberation Sans", "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", sans-serif';
      Chart.defaults.plugins.tooltip.backgroundColor = cardBg;
      Chart.defaults.plugins.tooltip.titleColor = fg;
      Chart.defaults.plugins.tooltip.bodyColor = fg;
      Chart.defaults.plugins.legend.labels.color = fg;
      Chart.defaults.scales.category.grid.color = hexToRGBA(grid, 0.6);
      Chart.defaults.scales.linear.grid.color = hexToRGBA(grid, 0.6);
  
      // Helper disponible pour vos pages
      window.AppThemeChart = {
        colors: { primary, fg, grid, cardBg, credit: creditColor, debit: debitColor, net: netColor },
        hexToRGBA: hexToRGBA
      };
    });
  })();