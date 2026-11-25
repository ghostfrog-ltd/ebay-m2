define(['jquery', 'chartJs'], function ($, Chart) {
    'use strict';

    // Validated categorical order (light surface); never cycled. Extra slices fold into "Other".
    var SERIES = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'],
        OTHER = '#a3a29c',
        INK = '#52514e',
        GRID = 'rgba(0, 0, 0, 0.06)',
        fmt = new Intl.NumberFormat();

    function fold(map, max) {
        var entries = Object.keys(map).map(function (k) { return [k, Number(map[k]) || 0]; })
                .filter(function (e) { return e[1] > 0; }),
            head = entries.slice(0, max),
            rest = entries.slice(max).reduce(function (sum, e) { return sum + e[1]; }, 0);

        if (rest > 0) {
            head.push(['Other', rest]);
        }
        return head;
    }

    function doughnut(canvas, map) {
        var entries = fold(map, 7),
            total = entries.reduce(function (sum, e) { return sum + e[1]; }, 0);

        if (!total) {
            $(canvas).closest('.gf-chart').addClass('gf-chart--empty').text('No data');
            return;
        }
        new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: entries.map(function (e) { return e[0]; }),
                datasets: [{
                    data: entries.map(function (e) { return e[1]; }),
                    backgroundColor: entries.map(function (e, i) {
                        return e[0] === 'Other' ? OTHER : SERIES[i];
                    }),
                    borderColor: '#ffffff',
                    borderWidth: 2,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                layout: { padding: 4 },
                plugins: {
                    legend: {
                        // Right-hand legends truncate in narrow cards; below keeps every label whole.
                        position: 'bottom',
                        align: 'start',
                        labels: {
                            color: INK,
                            boxWidth: 10,
                            boxHeight: 10,
                            usePointStyle: true,
                            pointStyle: 'rectRounded',
                            generateLabels: function (chart) {
                                var base = Chart.overrides.doughnut.plugins.legend.labels.generateLabels(chart);

                                return base.map(function (item, i) {
                                    var v = entries[i][1];

                                    item.text = entries[i][0] + '  ' + fmt.format(v) +
                                        ' (' + (100 * v / total).toFixed(1) + '%)';
                                    item.fontColor = INK;
                                    return item;
                                });
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return ' ' + fmt.format(ctx.parsed) + ' (' +
                                    (100 * ctx.parsed / total).toFixed(1) + '%)';
                            }
                        }
                    }
                }
            }
        });
    }

    function bar(canvas, map, label) {
        var keys = Object.keys(map);

        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: keys,
                datasets: [{
                    label: label,
                    data: keys.map(function (k) { return map[k]; }),
                    backgroundColor: SERIES[0],
                    hoverBackgroundColor: '#1c5cab',
                    borderRadius: { topLeft: 4, topRight: 4 },
                    borderSkipped: 'bottom',
                    maxBarThickness: 36
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) { return ' ' + fmt.format(ctx.parsed.y) + ' ' + label; }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: INK } },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: GRID },
                        ticks: { color: INK, precision: 0, callback: function (v) { return fmt.format(v); } }
                    }
                }
            }
        });
    }

    return function (config, element) {
        var data = config.data,
            daily = {};

        Chart.defaults.font.family = getComputedStyle(element).fontFamily;
        Object.keys(data.daily).forEach(function (d) { daily[d.slice(5)] = data.daily[d]; });

        $(element).find('canvas[data-chart]').each(function () {
            var name = this.getAttribute('data-chart');

            switch (name) {
                case 'daily':
                    bar(this, daily, 'new listings');
                    break;
                case 'compBuckets':
                    bar(this, data.compBuckets, 'comp groups');
                    break;
                case 'roi':
                    bar(this, data.roi, 'opportunities');
                    break;
                default:
                    doughnut(this, data[name] || {});
            }
        });
    };
});
