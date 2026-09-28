/**
 * Sri Lanka statutory reporting module (IRD, EPF/ETF, Labour, SLFRS statements).
 * Mounted by banquetdesk.app.js as the "Statutory (Sri Lanka)" tab in Financial Accounts.
 * Relies on the app's top-level GL helpers (glAccountBalances, glProfitAndLoss, glBalanceSheet, glAccountLedger).
 */
(function () {
    'use strict';

    var h = React.createElement;
    var useState = React.useState;
    var useEffect = React.useEffect;

    var DEFAULTS = {
        stat_tin: '',
        stat_business_reg_no: '',
        stat_vat_registered: '0',
        stat_vat_no: '',
        stat_vat_rate: '18',
        stat_sscl_registered: '0',
        stat_sscl_rate: '2.5',
        stat_sscl_liable_pct: '100',
        stat_tdl_applicable: '0',
        stat_tdl_rate: '1',
        stat_epf_employer_no: '',
        stat_etf_employer_no: '',
        stat_fy_start_month: '4',
        stat_income_tax_rate: '30',
        stat_gratuity_min_years: '5',
        stat_gratuity_months_per_year: '0.5',
        stat_signatory_name: '',
        stat_signatory_designation: '',
    };

    var WHT_TYPES = ['Service fee', 'Rent', 'Interest', 'Commission', 'Other'];

    var settingsCache = {};

    function setSettings(rows) {
        var next = {};
        (rows || []).forEach(function (row) {
            if (row && row.key != null && String(row.key).indexOf('stat_') === 0) {
                next[row.key] = row.value == null ? '' : String(row.value);
            }
        });
        settingsCache = next;
    }

    function currentSettings() {
        return Object.assign({}, DEFAULTS, settingsCache);
    }

    function invoiceTaxLine() {
        var s = currentSettings();
        var parts = [];
        if (s.stat_tin) {
            parts.push('TIN: ' + s.stat_tin);
        }
        if (s.stat_vat_registered === '1' && s.stat_vat_no) {
            parts.push('VAT Reg No: ' + s.stat_vat_no);
        }
        return parts.join('   |   ');
    }

    /* ------------------------------------------------------------------ helpers */

    function num(v) {
        var n = parseFloat(v);
        return isFinite(n) ? n : 0;
    }

    function round2(n) {
        return Math.round((num(n) + Number.EPSILON) * 100) / 100;
    }

    function money(n) {
        return num(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function sum(list, fn) {
        return (list || []).reduce(function (acc, item) {
            return acc + num(fn(item));
        }, 0);
    }

    function day(v) {
        return v ? String(v).slice(0, 10) : '';
    }

    function pad(n) {
        return String(n).padStart(2, '0');
    }

    function ymd(y, m, d) {
        return y + '-' + pad(m) + '-' + pad(d);
    }

    function lastDay(y, m) {
        return new Date(y, m, 0).getDate();
    }

    function shiftMonth(y, m, k) {
        var t = y * 12 + (m - 1) + k;
        return { y: Math.floor(t / 12), m: (t % 12) + 1 };
    }

    function prevDay(dateStr) {
        var d = new Date(dateStr + 'T12:00:00');
        d.setDate(d.getDate() - 1);
        return ymd(d.getFullYear(), d.getMonth() + 1, d.getDate());
    }

    function todayStr() {
        var d = new Date();
        return ymd(d.getFullYear(), d.getMonth() + 1, d.getDate());
    }

    function inRange(d, start, end) {
        d = day(d);
        return !!d && (!start || d >= start) && (!end || d <= end);
    }

    function monthName(y, m) {
        return new Date(y, m - 1, 1).toLocaleString('en-US', { month: 'long', year: 'numeric' });
    }

    function shortMonth(ym) {
        return new Date(+ym.slice(0, 4), +ym.slice(5, 7) - 1, 1).toLocaleString('en-US', { month: 'short', year: '2-digit' });
    }

    function monthsBetween(start, end) {
        var out = [];
        var y = +start.slice(0, 4);
        var m = +start.slice(5, 7);
        var endKey = end.slice(0, 7);
        for (var guard = 0; guard < 240; guard++) {
            var key = y + '-' + pad(m);
            out.push(key);
            if (key >= endKey) {
                break;
            }
            var n = shiftMonth(y, m, 1);
            y = n.y;
            m = n.m;
        }
        return out;
    }

    function daysBetween(from, to) {
        return Math.floor((new Date(to + 'T12:00:00') - new Date(from + 'T12:00:00')) / 86400000);
    }

    function completedYears(from, to) {
        if (!from || !to || from > to) {
            return 0;
        }
        var years = +to.slice(0, 4) - +from.slice(0, 4);
        if (to.slice(5) < from.slice(5)) {
            years -= 1;
        }
        return Math.max(0, years);
    }

    function fyStartMonth(s) {
        var m = parseInt(s.stat_fy_start_month, 10);
        return m >= 1 && m <= 12 ? m : 4;
    }

    function fyOf(dateStr, m0) {
        var y = +dateStr.slice(0, 4);
        var m = +dateStr.slice(5, 7);
        return m >= m0 ? y : y - 1;
    }

    function fyRange(y, m0) {
        var e = shiftMonth(y, m0, 11);
        return { start: ymd(y, m0, 1), end: ymd(e.y, e.m, lastDay(e.y, e.m)) };
    }

    function fyLabel(y, m0) {
        return m0 === 1 ? String(y) : y + '/' + (y + 1);
    }

    function parseJson(v) {
        if (v && typeof v === 'object') {
            return v;
        }
        try {
            return JSON.parse(v || 'null') || {};
        } catch (e) {
            return {};
        }
    }

    function uuid() {
        return typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : 'id-' + Date.now() + '-' + Math.random().toString(16).slice(2);
    }

    function isYes(v) {
        return v === true || v === 1 || String(v) === '1' || String(v).toLowerCase() === 'true';
    }

    function computePeriod(p, m0) {
        if (p.mode === 'month') {
            var y = +p.month.slice(0, 4);
            var m = +p.month.slice(5, 7);
            return { mode: 'month', start: ymd(y, m, 1), end: ymd(y, m, lastDay(y, m)), label: monthName(y, m), month: p.month, fy: fyOf(p.month + '-01', m0) };
        }
        if (p.mode === 'quarter') {
            var s = shiftMonth(p.fy, m0, 3 * (p.q - 1));
            var e = shiftMonth(s.y, s.m, 2);
            return {
                mode: 'quarter',
                start: ymd(s.y, s.m, 1),
                end: ymd(e.y, e.m, lastDay(e.y, e.m)),
                label: 'Quarter ' + p.q + ' of Y/A ' + fyLabel(p.fy, m0) + ' (' + monthName(s.y, s.m) + ' - ' + monthName(e.y, e.m) + ')',
                fy: p.fy,
            };
        }
        if (p.mode === 'half') {
            var sm = p.half === 1 ? 1 : 7;
            return {
                mode: 'half',
                start: ymd(p.year, sm, 1),
                end: ymd(p.year, sm + 5, lastDay(p.year, sm + 5)),
                label: (p.half === 1 ? 'January - June ' : 'July - December ') + p.year,
                fy: fyOf(ymd(p.year, sm + 5, 1), m0),
            };
        }
        if (p.mode === 'fy') {
            var r = fyRange(p.fy, m0);
            return { mode: 'fy', start: r.start, end: r.end, label: 'Year of Assessment ' + fyLabel(p.fy, m0) + ' (' + r.start + ' to ' + r.end + ')', fy: p.fy };
        }
        if (p.mode === 'asof') {
            return { mode: 'asof', start: null, end: p.asof, label: 'As at ' + p.asof, fy: fyOf(p.asof, m0) };
        }
        return { mode: 'custom', start: p.start, end: p.end, label: p.start + ' to ' + p.end, fy: fyOf(p.end, m0) };
    }

    /* ------------------------------------------------------------------ data helpers */

    function isFinalInvoice(i) {
        return String(i.document_kind || 'final') === 'final';
    }

    function isTaxInvoice(i) {
        var v = i.is_tax_invoice;
        return !(v != null && (v === 0 || v === false || String(v) === '0' || String(v).toLowerCase() === 'false'));
    }

    function invoiceDate(i) {
        return day(i.created_at) || day(i.event_date);
    }

    function invoiceTax(i) {
        return isTaxInvoice(i) ? num(i.tax_amount) : 0;
    }

    function invoiceNet(i) {
        return num(i.total_amount) - invoiceTax(i);
    }

    function salesInPeriod(ctx, per) {
        return (ctx.invoices || [])
            .filter(function (i) {
                return isFinalInvoice(i) && inRange(invoiceDate(i), per.start, per.end);
            })
            .sort(function (a, b) {
                return invoiceDate(a).localeCompare(invoiceDate(b)) || String(a.invoice_number || '').localeCompare(String(b.invoice_number || ''));
            });
    }

    function expensesInPeriod(ctx, per) {
        return (ctx.expenses || [])
            .filter(function (e) {
                return inRange(e.expense_date, per.start, per.end);
            })
            .sort(function (a, b) {
                return day(a.expense_date).localeCompare(day(b.expense_date));
            });
    }

    function slipView(ctx, slip, month) {
        var c = parseJson(slip.components);
        var snap = parseJson(slip.employee_snapshot);
        var emp = (ctx.employees || []).find(function (e) {
            return String(e.id) === String(slip.employee_id);
        }) || {};
        var person = Object.assign({}, snap, emp);
        var apit = c.apit != null
            ? num(c.apit)
            : sum((c.deductions || []).filter(function (d) {
                return /apit|paye/i.test(String(d.label || ''));
            }), function (d) {
                return d.value;
            });
        var epfEE = num(c.epfAmount);
        var epfER = num(c.epfEmployerAmount);
        return {
            month: month,
            employeeId: slip.employee_id,
            name: person.name || c.name || '',
            empNo: person.empNo || c.empNo || '',
            nic: person.nic || '',
            tin: person.tin || '',
            epfMember: c.is_epf_employee != null ? isYes(c.is_epf_employee) : epfEE + epfER > 0,
            basic: num(c.basicSalary),
            allowances: num(c.attendanceAllowance) + num(c.responsibilityAllowance) + num(c.otherAllowance) + num(c.leaveIncentive),
            gross: num(c.gross),
            epfEE: epfEE,
            epfER: epfER,
            etf: num(c.etfAmount),
            apit: apit,
            otherDeductions: Math.max(0, num(c.totalDeduction) - epfEE - (c.apit != null ? apit : 0)),
            net: num(c.net),
        };
    }

    function slipsForMonth(ctx, month) {
        var runs = (ctx.payrollRuns || []).filter(function (r) {
            return String(r.period) === month && !/void|cancel/i.test(String(r.status || ''));
        });
        if (!runs.length) {
            return [];
        }
        runs.sort(function (a, b) {
            return String(b.generated_at || b.created_at || '').localeCompare(String(a.generated_at || a.created_at || ''));
        });
        var runId = String(runs[0].id);
        return (ctx.payrollSlips || [])
            .filter(function (s) {
                return String(s.run_id) === runId;
            })
            .map(function (s) {
                return slipView(ctx, s, month);
            })
            .sort(function (a, b) {
                return String(a.empNo).localeCompare(String(b.empNo), undefined, { numeric: true }) || a.name.localeCompare(b.name);
            });
    }

    function payrollNote(ctx, months) {
        var missing = months.filter(function (m) {
            return !slipsForMonth(ctx, m).length;
        });
        return missing.length ? 'No payroll run found for: ' + missing.map(shortMonth).join(', ') + '. Generate payroll in HR > Payroll first.' : null;
    }

    function glReady() {
        return typeof glAccountBalances === 'function'
            && typeof glProfitAndLoss === 'function'
            && typeof glBalanceSheet === 'function'
            && typeof glAccountLedger === 'function';
    }

    function acctText(a) {
        return String(a.account_name || a.name || '') + ' ' + String(a.detail_type || a.detail || '');
    }

    var NON_CURRENT_ASSET = /fixed|furniture|equipment|vehicle|building|machinery|property|land|accumulated depreciation|non.?current|intangible|long.?term investment/i;
    var NON_CURRENT_LIABILITY = /long.?term|non.?current|lease liabilit/i;
    var CASH_ACCOUNT = /cash|bank|petty|undeposited/i;
    var DEPRECIATION = /depreciation|amortis|amortiz/i;

    function cashAccounts(ctx) {
        return (ctx.coa || []).filter(function (a) {
            return a.account_type === 'Asset' && CASH_ACCOUNT.test(acctText(a)) && !NON_CURRENT_ASSET.test(acctText(a));
        });
    }

    function profitAndLoss(ctx, start, end) {
        return glProfitAndLoss(ctx.journals, ctx.coa, start, end, ctx.vouchers);
    }

    function balancesAt(ctx, asOf) {
        return glAccountBalances(ctx.journals, ctx.coa, asOf, ctx.vouchers);
    }

    /* ------------------------------------------------------------------ fixed assets */

    function monthIndex(dateStr) {
        return +dateStr.slice(0, 4) * 12 + (+dateStr.slice(5, 7) - 1);
    }

    function accumulatedDepreciation(asset, asOf) {
        var cost = num(asset.cost);
        var residual = Math.min(num(asset.residual_value), cost);
        var life = Math.max(num(asset.useful_life_years), 0.1);
        if (!asset.acquisition_date || cost <= 0 || !asOf) {
            return 0;
        }
        var endIdx = monthIndex(asOf);
        if (asset.disposal_date) {
            endIdx = Math.min(endIdx, monthIndex(day(asset.disposal_date)));
        }
        var months = endIdx - monthIndex(day(asset.acquisition_date)) + 1;
        if (months <= 0) {
            return 0;
        }
        if (String(asset.depreciation_method) === 'reducing_balance') {
            var rate = residual > 0 ? 1 - Math.pow(residual / cost, 1 / life) : Math.min(1, 2 / life);
            var nbv = cost;
            for (var i = 0; i < Math.min(months, 1200); i++) {
                nbv -= (nbv * rate) / 12;
                if (nbv <= residual) {
                    nbv = residual;
                    break;
                }
            }
            return cost - nbv;
        }
        var totalMonths = Math.max(1, Math.round(life * 12));
        return Math.min(cost - residual, ((cost - residual) / totalMonths) * Math.min(months, totalMonths));
    }

    function capitalAllowance(asset, fy, m0) {
        var cost = num(asset.cost);
        var rate = num(asset.capital_allowance_rate) / 100;
        if (!asset.acquisition_date || cost <= 0 || rate <= 0) {
            return { allowance: 0, claimedBefore: 0, taxWdv: cost };
        }
        var acquiredFy = fyOf(day(asset.acquisition_date), m0);
        var disposedFy = asset.disposal_date ? fyOf(day(asset.disposal_date), m0) : Infinity;
        if (fy < acquiredFy) {
            return { allowance: 0, claimedBefore: 0, taxWdv: cost };
        }
        var annual = cost * rate;
        var claimed = 0;
        for (var y = acquiredFy; y < fy && y < disposedFy; y++) {
            claimed = Math.min(cost, claimed + annual);
        }
        var allowance = fy >= disposedFy ? 0 : Math.min(annual, cost - claimed);
        return { allowance: allowance, claimedBefore: claimed, taxWdv: cost - claimed - allowance };
    }

    function assetRegister(ctx, fy) {
        var range = fyRange(fy, ctx.m0);
        return (ctx.fixedAssets || [])
            .filter(function (a) {
                return a.acquisition_date && day(a.acquisition_date) <= range.end;
            })
            .map(function (a) {
                var accEnd = accumulatedDepreciation(a, range.end);
                var accStart = accumulatedDepreciation(a, prevDay(range.start));
                var disposed = a.disposal_date && day(a.disposal_date) <= range.end;
                var ca = capitalAllowance(a, fy, ctx.m0);
                return {
                    asset: a,
                    depreciation: accEnd - accStart,
                    accumulated: accEnd,
                    nbv: disposed ? 0 : num(a.cost) - accEnd,
                    disposed: !!disposed,
                    allowance: ca.allowance,
                    taxWdv: disposed ? 0 : ca.taxWdv,
                };
            })
            .sort(function (a, b) {
                return String(a.asset.asset_code || a.asset.name).localeCompare(String(b.asset.asset_code || b.asset.name), undefined, { numeric: true });
            });
    }

    /* ------------------------------------------------------------------ reports */

    function rVat(ctx, per) {
        var s = ctx.settings;
        var sales = salesInPeriod(ctx, per);
        var taxed = sales.filter(function (i) {
            return invoiceTax(i) > 0;
        });
        var other = sales.filter(function (i) {
            return !(invoiceTax(i) > 0);
        });
        var purchases = expensesInPeriod(ctx, per).filter(function (e) {
            return num(e.vat_amount) > 0;
        });
        var outVal = sum(taxed, invoiceNet);
        var outTax = sum(taxed, invoiceTax);
        var otherVal = sum(other, invoiceNet);
        var inTax = sum(purchases, function (e) {
            return e.vat_amount;
        });
        var inVal = sum(purchases, function (e) {
            return num(e.amount) - num(e.vat_amount);
        });
        var net = outTax - inTax;
        var notes = [
            'Output VAT is taken from final tax invoices dated in the period; input VAT from expenses where a VAT amount was entered.',
            'File the VAT return and pay through the IRD e-Services (RAMIS) portal. Confirm the current filing deadlines and rates with IRD or your accountant.',
        ];
        if (s.stat_vat_registered !== '1') {
            notes.unshift('This company is not marked as VAT registered in the Statutory Profile. VAT returns are only required once registered.');
        }
        return {
            title: 'VAT Return Schedule',
            summary: [
                ['VAT registration no.', s.stat_vat_no || '-'],
                ['Standard rate', num(s.stat_vat_rate) + '%'],
                ['Taxable supplies (excl. VAT)', outVal],
                ['Output VAT', outTax],
                ['Supplies without VAT', otherVal],
                ['Purchases with VAT (excl. VAT)', inVal],
                ['Input VAT claimed', inTax],
                [net >= 0 ? 'Net VAT payable' : 'Excess input VAT (carry forward / refund)', Math.abs(net)],
            ],
            sections: [
                {
                    heading: 'Schedule 1 - Output tax (tax invoices issued)',
                    head: ['Date', 'Invoice No', 'Customer', 'Value excl. VAT', 'VAT', 'Total'],
                    rows: taxed.map(function (i) {
                        return [invoiceDate(i), i.invoice_number || '', i.customer_name || '', invoiceNet(i), invoiceTax(i), num(i.total_amount)];
                    }),
                    foot: ['', '', 'Total', outVal, outTax, outVal + outTax],
                },
                {
                    heading: 'Supplies invoiced without VAT',
                    head: ['Date', 'Invoice No', 'Customer', 'Amount'],
                    rows: other.map(function (i) {
                        return [invoiceDate(i), i.invoice_number || '', i.customer_name || '', invoiceNet(i)];
                    }),
                    foot: ['', '', 'Total', otherVal],
                },
                {
                    heading: 'Schedule 2 - Input tax (purchases with VAT)',
                    head: ['Date', 'Reference', 'Supplier', 'Supplier VAT No', 'Supplier Invoice', 'Value excl. VAT', 'VAT'],
                    rows: purchases.map(function (e) {
                        return [day(e.expense_date), e.reference_no || '', e.payee || '', e.supplier_vat_no || '', e.supplier_invoice_no || '', num(e.amount) - num(e.vat_amount), num(e.vat_amount)];
                    }),
                    foot: ['', '', '', '', 'Total', inVal, inTax],
                },
            ],
            notes: notes,
        };
    }

    function turnoverByMonth(ctx, per) {
        var sales = salesInPeriod(ctx, per);
        return monthsBetween(per.start, per.end).map(function (m) {
            var rows = sales.filter(function (i) {
                return invoiceDate(i).slice(0, 7) === m;
            });
            return { month: m, count: rows.length, turnover: sum(rows, invoiceNet) };
        });
    }

    function rSscl(ctx, per) {
        var s = ctx.settings;
        var rate = num(s.stat_sscl_rate);
        var liablePct = num(s.stat_sscl_liable_pct);
        var months = turnoverByMonth(ctx, per);
        var rows = months.map(function (m) {
            var liable = (m.turnover * liablePct) / 100;
            return [shortMonth(m.month), String(m.count), m.turnover, liable, (liable * rate) / 100];
        });
        var turnover = sum(months, function (m) {
            return m.turnover;
        });
        var liable = (turnover * liablePct) / 100;
        var notes = ['Turnover is the value of final invoices excluding VAT. Adjust the liable turnover % in the Statutory Profile if only part of your turnover is liable.', 'Confirm the SSCL rate, registration threshold and filing dates with IRD or your accountant.'];
        if (s.stat_sscl_registered !== '1') {
            notes.unshift('This company is not marked as SSCL registered in the Statutory Profile.');
        }
        return {
            title: 'Social Security Contribution Levy (SSCL) Schedule',
            summary: [
                ['Company TIN', s.stat_tin || '-'],
                ['SSCL rate', rate + '%'],
                ['Liable portion of turnover', liablePct + '%'],
                ['Turnover (excl. VAT)', turnover],
                ['Liable turnover', liable],
                ['SSCL payable', (liable * rate) / 100],
            ],
            sections: [
                {
                    heading: 'SSCL by month',
                    head: ['Month', 'Invoices', 'Turnover', 'Liable turnover', 'SSCL'],
                    rows: rows,
                    foot: ['Total', '', turnover, liable, (liable * rate) / 100],
                },
            ],
            notes: notes,
        };
    }

    function rTdl(ctx, per) {
        var s = ctx.settings;
        var rate = num(s.stat_tdl_rate);
        var sales = salesInPeriod(ctx, per);
        var turnover = sum(sales, invoiceNet);
        var notes = ['Tourism Development Levy is calculated on turnover excluding VAT. Pay to the Sri Lanka Tourism Development Authority; confirm the rate and due date with SLTDA.'];
        if (s.stat_tdl_applicable !== '1') {
            notes.unshift('TDL is not marked as applicable in the Statutory Profile (only SLTDA-registered tourist establishments pay TDL).');
        }
        return {
            title: 'Tourism Development Levy (TDL) Schedule',
            summary: [
                ['TDL rate', rate + '%'],
                ['Turnover (excl. VAT)', turnover],
                ['TDL payable', (turnover * rate) / 100],
            ],
            sections: [
                {
                    heading: 'Turnover subject to TDL',
                    head: ['Date', 'Invoice No', 'Customer', 'Turnover', 'TDL'],
                    rows: sales.map(function (i) {
                        return [invoiceDate(i), i.invoice_number || '', i.customer_name || '', invoiceNet(i), (invoiceNet(i) * rate) / 100];
                    }),
                    foot: ['', '', 'Total', turnover, (turnover * rate) / 100],
                },
            ],
            notes: notes,
        };
    }

    function rApitMonthly(ctx, per) {
        var slips = slipsForMonth(ctx, per.month).filter(function (x) {
            return x.gross > 0 || x.apit > 0;
        });
        var totalApit = sum(slips, function (x) {
            return x.apit;
        });
        var notes = ['APIT amounts are the manual monthly APIT entered on each employee (HR > Employee > Statutory). Remit APIT to IRD by the 15th of the following month (confirm with IRD).'];
        var missing = payrollNote(ctx, [per.month]);
        if (missing) {
            notes.unshift(missing);
        }
        return {
            title: 'APIT Monthly Schedule',
            summary: [
                ['Employer TIN', ctx.settings.stat_tin || '-'],
                ['Employees', String(slips.length)],
                ['Total remuneration', sum(slips, function (x) { return x.gross; })],
                ['Total APIT deducted', totalApit],
            ],
            sections: [
                {
                    heading: 'Employees',
                    head: ['Emp No', 'Name', 'NIC', 'TIN', 'Gross remuneration', 'APIT deducted'],
                    rows: slips.map(function (x) {
                        return [x.empNo, x.name, x.nic, x.tin, x.gross, x.apit];
                    }),
                    foot: ['', 'Total', '', '', sum(slips, function (x) { return x.gross; }), totalApit],
                },
            ],
            notes: notes,
        };
    }

    function annualApit(ctx, per) {
        var months = monthsBetween(per.start, per.end);
        var people = {};
        months.forEach(function (m) {
            slipsForMonth(ctx, m).forEach(function (x) {
                var p = people[x.employeeId] || (people[x.employeeId] = { empNo: x.empNo, name: x.name, nic: x.nic, tin: x.tin, gross: 0, apit: 0, months: {} });
                p.gross += x.gross;
                p.apit += x.apit;
                p.months[m] = { gross: x.gross, apit: x.apit };
            });
        });
        return {
            months: months,
            list: Object.keys(people)
                .map(function (k) {
                    return people[k];
                })
                .sort(function (a, b) {
                    return String(a.empNo).localeCompare(String(b.empNo), undefined, { numeric: true });
                }),
        };
    }

    function rApitAnnual(ctx, per) {
        var data = annualApit(ctx, per);
        var notes = ['Use this schedule for the annual APIT declaration. Issue a T-10 certificate to each employee (button above).'];
        var missing = payrollNote(ctx, data.months);
        if (missing) {
            notes.unshift(missing);
        }
        return {
            title: 'APIT Annual Declaration Schedule',
            summary: [
                ['Employer TIN', ctx.settings.stat_tin || '-'],
                ['Employees', String(data.list.length)],
                ['Total remuneration', sum(data.list, function (p) { return p.gross; })],
                ['Total APIT deducted', sum(data.list, function (p) { return p.apit; })],
            ],
            sections: [
                {
                    heading: 'Employees',
                    head: ['Emp No', 'Name', 'NIC', 'TIN', 'Gross remuneration', 'APIT deducted'],
                    rows: data.list.map(function (p) {
                        return [p.empNo, p.name, p.nic, p.tin, p.gross, p.apit];
                    }),
                    foot: ['', 'Total', '', '', sum(data.list, function (p) { return p.gross; }), sum(data.list, function (p) { return p.apit; })],
                },
            ],
            notes: notes,
            actions: [
                {
                    label: 'T-10 certificates (PDF)',
                    run: function () {
                        exportT10(ctx, per, data);
                    },
                },
            ],
        };
    }

    function whtRows(ctx, per) {
        return expensesInPeriod(ctx, per).filter(function (e) {
            return num(e.wht_amount) > 0;
        });
    }

    function rWht(ctx, per) {
        var rows = whtRows(ctx, per);
        var byType = {};
        rows.forEach(function (e) {
            var t = e.wht_type || 'Other';
            byType[t] = byType[t] || { gross: 0, wht: 0 };
            byType[t].gross += num(e.amount);
            byType[t].wht += num(e.wht_amount);
        });
        return {
            title: 'Withholding Tax (WHT) Schedule',
            summary: [
                ['Withholding agent TIN', ctx.settings.stat_tin || '-'],
                ['Payments with WHT', String(rows.length)],
                ['Gross payments', sum(rows, function (e) { return e.amount; })],
                ['Total WHT deducted', sum(rows, function (e) { return e.wht_amount; })],
            ],
            sections: [
                {
                    heading: 'Summary by payment type',
                    head: ['Payment type', 'Gross amount', 'WHT'],
                    rows: Object.keys(byType).map(function (t) {
                        return [t, byType[t].gross, byType[t].wht];
                    }),
                },
                {
                    heading: 'Payments',
                    head: ['Date', 'Reference', 'Payee', 'Payee TIN', 'Type', 'Gross amount', 'WHT'],
                    rows: rows.map(function (e) {
                        return [day(e.expense_date), e.reference_no || '', e.payee || '', e.supplier_tin || '', e.wht_type || 'Other', num(e.amount), num(e.wht_amount)];
                    }),
                    foot: ['', '', '', '', 'Total', sum(rows, function (e) { return e.amount; }), sum(rows, function (e) { return e.wht_amount; })],
                },
            ],
            notes: ['WHT is recorded on expenses (Financial Accounts > Expenses). Remit WHT to IRD by the 15th of the following month and issue a WHT certificate to each payee (confirm rates and dates with IRD).'],
            actions: [
                {
                    label: 'WHT certificates (PDF)',
                    run: function () {
                        exportWhtCertificates(ctx, per, rows);
                    },
                },
            ],
        };
    }

    function incomeTaxComputation(ctx, per, ui) {
        var s = ctx.settings;
        var saved = parseJson(s['stat_it_adj_' + per.fy]);
        var adj = Object.assign({ disallowed: 0, exempt: 0, otherDeductions: 0, lossesBf: 0, whtCredits: 0, instalmentsPaid: 0 }, saved, ui.adj || {});
        var pl = profitAndLoss(ctx, per.start, per.end);
        var glDepreciation = sum(pl.opex.filter(function (l) {
            return DEPRECIATION.test(l.name + ' ' + l.detail);
        }), function (l) {
            return l.amount;
        });
        var register = assetRegister(ctx, per.fy);
        var allowances = sum(register, function (r) {
            return r.allowance;
        });
        var registerDep = sum(register, function (r) {
            return r.depreciation;
        });
        var adjusted = pl.netIncome + glDepreciation + num(adj.disallowed) - allowances - num(adj.exempt) - num(adj.otherDeductions);
        var lossUsed = Math.min(Math.max(0, adjusted), num(adj.lossesBf));
        var taxable = Math.max(0, adjusted - lossUsed);
        var rate = num(s.stat_income_tax_rate);
        var tax = (taxable * rate) / 100;
        var balance = tax - num(adj.whtCredits) - num(adj.instalmentsPaid);
        return {
            adj: adj,
            pl: pl,
            glDepreciation: glDepreciation,
            registerDep: registerDep,
            allowances: allowances,
            adjusted: adjusted,
            lossUsed: lossUsed,
            taxable: taxable,
            rate: rate,
            tax: tax,
            balance: balance,
        };
    }

    function rIncomeTax(ctx, per, ui) {
        var c = incomeTaxComputation(ctx, per, ui);
        var y = per.fy;
        var fyEnd = fyRange(y, ctx.m0).end;
        var next = +fyEnd.slice(0, 4);
        var notes = [
            'Estimated computation for planning. Disallowed expenses, exempt income, losses and credits are entered above and saved per year of assessment.',
            'Capital allowances come from the Fixed Asset Register. Confirm tax rates, allowances and due dates with IRD or your accountant before filing.',
        ];
        if (!c.glDepreciation && c.registerDep > 0) {
            notes.unshift('Depreciation from the Fixed Asset Register (' + money(c.registerDep) + ') has not been posted to the ledger, so it is not added back.');
        }
        return {
            title: 'Income Tax Computation (Estimate)',
            summary: [
                ['Company TIN', ctx.settings.stat_tin || '-'],
                ['Taxable income', c.taxable],
                ['Income tax @ ' + c.rate + '%', c.tax],
                [c.balance >= 0 ? 'Balance tax payable' : 'Tax overpaid', Math.abs(c.balance)],
            ],
            sections: [
                {
                    heading: 'Computation',
                    head: ['Item', 'Amount'],
                    rows: [
                        ['Profit before tax (per accounts)', c.pl.netIncome],
                        ['Add: Depreciation charged in the accounts', c.glDepreciation],
                        ['Add: Disallowed expenses', num(c.adj.disallowed)],
                        ['Less: Capital allowances', -c.allowances],
                        ['Less: Exempt / separately taxed income', -num(c.adj.exempt)],
                        ['Less: Other allowable deductions', -num(c.adj.otherDeductions)],
                        ['Adjusted business income', c.adjusted],
                        ['Less: Tax losses brought forward used', -c.lossUsed],
                        ['Taxable income', c.taxable],
                        ['Income tax @ ' + c.rate + '%', c.tax],
                        ['Less: WHT credits', -num(c.adj.whtCredits)],
                        ['Less: Instalments already paid', -num(c.adj.instalmentsPaid)],
                        ['Balance payable / (refundable)', c.balance],
                    ],
                },
                {
                    heading: 'Quarterly instalment plan (typical due dates)',
                    head: ['Instalment', 'Due date', 'Amount'],
                    rows: [
                        ['1st quarter', ymd(y, 8, 15), c.tax / 4],
                        ['2nd quarter', ymd(y, 11, 15), c.tax / 4],
                        ['3rd quarter', ymd(y + 1, 2, 15), c.tax / 4],
                        ['4th quarter', ymd(y + 1, 5, 15), c.tax / 4],
                        ['Final payment', ymd(next, 9, 30), ''],
                        ['Return of income due', ymd(next, 11, 30), ''],
                    ],
                },
            ],
            notes: notes,
        };
    }

    function rEpfC(ctx, per) {
        var slips = slipsForMonth(ctx, per.month).filter(function (x) {
            return x.epfMember && x.epfEE + x.epfER > 0;
        });
        var notes = ['Submit Form C with the contribution to the EPF Department / Central Bank before the end of the following month (confirm the current deadline).'];
        var missing = payrollNote(ctx, [per.month]);
        if (missing) {
            notes.unshift(missing);
        }
        var totalEarn = sum(slips, function (x) { return x.gross; });
        var totalER = sum(slips, function (x) { return x.epfER; });
        var totalEE = sum(slips, function (x) { return x.epfEE; });
        return {
            title: 'EPF Form C - Monthly Return',
            summary: [
                ['EPF employer registration no.', ctx.settings.stat_epf_employer_no || '-'],
                ['Members', String(slips.length)],
                ['Total earnings', totalEarn],
                ['Employer contribution', totalER],
                ['Employee contribution', totalEE],
                ['Total contribution', totalER + totalEE],
            ],
            sections: [
                {
                    heading: 'Members',
                    head: ['Member No', 'NIC', 'Name', 'Total earnings', 'Employer', 'Employee', 'Total'],
                    rows: slips.map(function (x) {
                        return [x.empNo, x.nic, x.name, x.gross, x.epfER, x.epfEE, x.epfER + x.epfEE];
                    }),
                    foot: ['', '', 'Total', totalEarn, totalER, totalEE, totalER + totalEE],
                },
            ],
            notes: notes,
        };
    }

    function halfYearData(ctx, per, pick) {
        var months = monthsBetween(per.start, per.end);
        var people = {};
        months.forEach(function (m) {
            slipsForMonth(ctx, m).forEach(function (x) {
                if (!x.epfMember) {
                    return;
                }
                var p = people[x.employeeId] || (people[x.employeeId] = { empNo: x.empNo, nic: x.nic, name: x.name, earnings: 0, ee: 0, er: 0, etf: 0, months: {} });
                p.earnings += x.gross;
                p.ee += x.epfEE;
                p.er += x.epfER;
                p.etf += x.etf;
                p.months[m] = pick(x);
            });
        });
        return {
            months: months,
            list: Object.keys(people)
                .map(function (k) {
                    return people[k];
                })
                .sort(function (a, b) {
                    return String(a.empNo).localeCompare(String(b.empNo), undefined, { numeric: true });
                }),
        };
    }

    function rEpfHalf(ctx, per) {
        var data = halfYearData(ctx, per, function (x) {
            return x.epfEE + x.epfER;
        });
        var notes = [];
        var missing = payrollNote(ctx, data.months);
        if (missing) {
            notes.push(missing);
        }
        return {
            title: 'EPF Half-Yearly Contribution Summary',
            summary: [
                ['EPF employer registration no.', ctx.settings.stat_epf_employer_no || '-'],
                ['Members', String(data.list.length)],
                ['Employer contributions', sum(data.list, function (p) { return p.er; })],
                ['Employee contributions', sum(data.list, function (p) { return p.ee; })],
            ],
            sections: [
                {
                    heading: 'Total contribution by member and month',
                    head: ['Member No', 'NIC', 'Name'].concat(data.months.map(shortMonth), ['Total']),
                    rows: data.list.map(function (p) {
                        return [p.empNo, p.nic, p.name].concat(data.months.map(function (m) {
                            return num(p.months[m]);
                        }), [p.ee + p.er]);
                    }),
                    foot: ['', '', 'Total'].concat(data.months.map(function (m) {
                        return sum(data.list, function (p) { return p.months[m]; });
                    }), [sum(data.list, function (p) { return p.ee + p.er; })]),
                },
            ],
            notes: notes,
        };
    }

    function rEtfMonthly(ctx, per) {
        var slips = slipsForMonth(ctx, per.month).filter(function (x) {
            return x.epfMember && x.etf > 0;
        });
        var notes = ['Pay ETF (3% of total earnings) to the Employees\' Trust Fund Board by the end of the following month (confirm the current deadline).'];
        var missing = payrollNote(ctx, [per.month]);
        if (missing) {
            notes.unshift(missing);
        }
        return {
            title: 'ETF Monthly Contribution Schedule',
            summary: [
                ['ETF employer registration no.', ctx.settings.stat_etf_employer_no || ctx.settings.stat_epf_employer_no || '-'],
                ['Members', String(slips.length)],
                ['Total earnings', sum(slips, function (x) { return x.gross; })],
                ['ETF payable', sum(slips, function (x) { return x.etf; })],
            ],
            sections: [
                {
                    heading: 'Members',
                    head: ['Member No', 'NIC', 'Name', 'Total earnings', 'ETF contribution'],
                    rows: slips.map(function (x) {
                        return [x.empNo, x.nic, x.name, x.gross, x.etf];
                    }),
                    foot: ['', '', 'Total', sum(slips, function (x) { return x.gross; }), sum(slips, function (x) { return x.etf; })],
                },
            ],
            notes: notes,
        };
    }

    function rEtfForm2(ctx, per) {
        var data = halfYearData(ctx, per, function (x) {
            return x.etf;
        });
        var notes = ['Form II is the half-yearly ETF return (January - June and July - December). Submit to the ETF Board with member details.'];
        var missing = payrollNote(ctx, data.months);
        if (missing) {
            notes.unshift(missing);
        }
        return {
            title: 'ETF Form II - Half-Yearly Return',
            summary: [
                ['ETF employer registration no.', ctx.settings.stat_etf_employer_no || ctx.settings.stat_epf_employer_no || '-'],
                ['Members', String(data.list.length)],
                ['Total earnings', sum(data.list, function (p) { return p.earnings; })],
                ['Total ETF contributions', sum(data.list, function (p) { return p.etf; })],
            ],
            sections: [
                {
                    heading: 'ETF contribution by member and month',
                    head: ['Member No', 'NIC', 'Name'].concat(data.months.map(shortMonth), ['Total ETF', 'Total earnings']),
                    rows: data.list.map(function (p) {
                        return [p.empNo, p.nic, p.name].concat(data.months.map(function (m) {
                            return num(p.months[m]);
                        }), [p.etf, p.earnings]);
                    }),
                    foot: ['', '', 'Total'].concat(data.months.map(function (m) {
                        return sum(data.list, function (p) { return p.months[m]; });
                    }), [sum(data.list, function (p) { return p.etf; }), sum(data.list, function (p) { return p.earnings; })]),
                },
            ],
            notes: notes,
        };
    }

    function rGratuity(ctx, per) {
        var s = ctx.settings;
        var minYears = num(s.stat_gratuity_min_years) || 5;
        var monthsPerYear = num(s.stat_gratuity_months_per_year) || 0.5;
        var rows = (ctx.employees || [])
            .filter(function (e) {
                return String(e.status || 'Active') !== 'Resigned' && e.joinDate && day(e.joinDate) <= per.end;
            })
            .map(function (e) {
                var years = completedYears(day(e.joinDate), per.end);
                var basic = num(e.basicSalary);
                var accrued = basic * monthsPerYear * years;
                var eligible = years >= minYears;
                return { e: e, years: years, basic: basic, accrued: accrued, eligible: eligible };
            })
            .sort(function (a, b) {
                return b.years - a.years;
            });
        var liability = sum(rows.filter(function (r) { return r.eligible; }), function (r) { return r.accrued; });
        var provision = sum(rows, function (r) { return r.accrued; });
        return {
            title: 'Gratuity Liability Schedule',
            summary: [
                ['Employees', String(rows.length)],
                ['Eligible (' + minYears + '+ years)', String(rows.filter(function (r) { return r.eligible; }).length)],
                ['Gratuity payable if all eligible staff left', liability],
                ['Accrued provision (all staff)', provision],
            ],
            sections: [
                {
                    heading: 'Employees',
                    head: ['Emp No', 'Name', 'Joined', 'Completed years', 'Monthly basic', 'Eligible', 'Accrued gratuity'],
                    rows: rows.map(function (r) {
                        return [r.e.empNo || '', r.e.name || '', day(r.e.joinDate), String(r.years), r.basic, r.eligible ? 'Yes' : 'No', r.accrued];
                    }),
                    foot: ['', 'Total', '', '', '', '', provision],
                },
            ],
            notes: ['Based on the Payment of Gratuity Act No. 12 of 1983: ' + monthsPerYear + ' month(s) of the last monthly basic for each completed year of service, payable after ' + minYears + ' years. Rates are editable in the Statutory Profile; confirm with your accountant.'],
        };
    }

    function rSalaryRegister(ctx, per) {
        var slips = slipsForMonth(ctx, per.month);
        var notes = [];
        var missing = payrollNote(ctx, [per.month]);
        if (missing) {
            notes.push(missing);
        }
        function total(k) {
            return sum(slips, function (x) { return x[k]; });
        }
        return {
            title: 'Salary & Wage Register',
            summary: [
                ['Employees', String(slips.length)],
                ['Gross pay', total('gross')],
                ['Net pay', total('net')],
                ['Employer EPF + ETF', total('epfER') + total('etf')],
            ],
            sections: [
                {
                    heading: 'Register',
                    head: ['Emp No', 'Name', 'Basic', 'Allowances', 'Gross', 'EPF 8%', 'APIT', 'Other ded.', 'Net pay', 'EPF 12%', 'ETF 3%'],
                    rows: slips.map(function (x) {
                        return [x.empNo, x.name, x.basic, x.allowances, x.gross, x.epfEE, x.apit, x.otherDeductions, x.net, x.epfER, x.etf];
                    }),
                    foot: ['', 'Total', total('basic'), total('allowances'), total('gross'), total('epfEE'), total('apit'), total('otherDeductions'), total('net'), total('epfER'), total('etf')],
                },
            ],
            notes: notes,
        };
    }

    function previousPeriod(per, m0) {
        if (per.mode !== 'fy') {
            return null;
        }
        var r = fyRange(per.fy - 1, m0);
        return { start: r.start, end: r.end, label: fyLabel(per.fy - 1, m0), fy: per.fy - 1 };
    }

    function mergeLines(cur, prev) {
        var map = {};
        cur.forEach(function (l) {
            map[l.name] = { name: l.name, cur: l.amount, prev: 0 };
        });
        (prev || []).forEach(function (l) {
            map[l.name] = map[l.name] || { name: l.name, cur: 0, prev: 0 };
            map[l.name].prev = l.amount;
        });
        return Object.keys(map).map(function (k) {
            return map[k];
        });
    }

    function rProfitOrLoss(ctx, per) {
        var prev = previousPeriod(per, ctx.m0);
        var cur = profitAndLoss(ctx, per.start, per.end);
        var old = prev ? profitAndLoss(ctx, prev.start, prev.end) : null;
        var head = prev ? ['', fyLabel(per.fy, ctx.m0), prev.label] : ['', 'Amount'];
        function line(label, a, b) {
            return prev ? [label, a, b] : [label, a];
        }
        var rows = [];
        rows.push(line('Revenue', '', ''));
        mergeLines(cur.income, old && old.income).forEach(function (l) {
            rows.push(line('   ' + l.name, l.cur, l.prev));
        });
        rows.push(line('Total revenue', cur.totalIncome, old ? old.totalIncome : 0));
        rows.push(line('Cost of sales', '', ''));
        mergeLines(cur.cogs, old && old.cogs).forEach(function (l) {
            rows.push(line('   ' + l.name, -l.cur, -l.prev));
        });
        rows.push(line('Gross profit', cur.grossProfit, old ? old.grossProfit : 0));
        rows.push(line('Operating expenses', '', ''));
        mergeLines(cur.opex, old && old.opex).forEach(function (l) {
            rows.push(line('   ' + l.name, -l.cur, -l.prev));
        });
        rows.push(line('Profit before tax', cur.netIncome, old ? old.netIncome : 0));
        var summary = [
            ['Revenue', cur.totalIncome],
            ['Gross profit', cur.grossProfit],
            ['Profit before tax', cur.netIncome],
        ];
        if (per.mode === 'fy') {
            var tax = incomeTaxComputation(ctx, per, {}).tax;
            rows.push(line('Income tax expense (estimate)', -tax, ''));
            rows.push(line('Profit for the year', cur.netIncome - tax, ''));
            summary.push(['Income tax (estimate)', tax], ['Profit for the year', cur.netIncome - tax]);
        }
        return {
            title: 'Statement of Profit or Loss',
            summary: summary,
            sections: [{ heading: 'For the period ' + per.label, head: head, rows: rows }],
            notes: ['Prepared from the general ledger on an SLFRS for SMEs basis. Figures in brackets are deductions.'],
        };
    }

    function classifyPosition(ctx, asOf) {
        var bs = glBalanceSheet(ctx.journals, ctx.coa, asOf, ctx.vouchers);
        var ncAssets = bs.assets.filter(function (a) { return NON_CURRENT_ASSET.test(acctText(a)); });
        var cAssets = bs.assets.filter(function (a) { return !NON_CURRENT_ASSET.test(acctText(a)); });
        var ncLiab = bs.liabilities.filter(function (a) { return NON_CURRENT_LIABILITY.test(acctText(a)); });
        var cLiab = bs.liabilities.filter(function (a) { return !NON_CURRENT_LIABILITY.test(acctText(a)); });
        return { bs: bs, ncAssets: ncAssets, cAssets: cAssets, ncLiab: ncLiab, cLiab: cLiab };
    }

    function rFinancialPosition(ctx, per) {
        var prev = previousPeriod(per, ctx.m0);
        var cur = classifyPosition(ctx, per.end);
        var old = prev ? classifyPosition(ctx, prev.end) : null;
        function amountIn(list, acct) {
            var hit = (list || []).find(function (a) {
                return String(a.id) === String(acct.id) && a.account_name === acct.account_name;
            });
            return hit ? hit.balance : 0;
        }
        function group(label, curList, oldList) {
            var names = curList.slice();
            (oldList || []).forEach(function (a) {
                if (!names.some(function (x) { return String(x.id) === String(a.id); })) {
                    names.push(a);
                }
            });
            var rows = [prev ? [label, '', ''] : [label, '']];
            names.forEach(function (a) {
                var label2 = '   ' + (a.account_code ? a.account_code + ' ' : '') + a.account_name;
                rows.push(prev ? [label2, amountIn(curList, a), amountIn(oldList, a)] : [label2, amountIn(curList, a)]);
            });
            var t = sum(curList, function (a) { return a.balance; });
            var t2 = sum(oldList || [], function (a) { return a.balance; });
            rows.push(prev ? ['Total ' + label.toLowerCase(), t, t2] : ['Total ' + label.toLowerCase(), t]);
            return rows;
        }
        function line(label, a, b) {
            return prev ? [label, a, b] : [label, a];
        }
        var head = prev ? ['', 'As at ' + per.end, 'As at ' + prev.end] : ['', 'As at ' + per.end];
        var assetRows = [].concat(
            group('Non-current assets', cur.ncAssets, old && old.ncAssets),
            group('Current assets', cur.cAssets, old && old.cAssets),
            [line('TOTAL ASSETS', cur.bs.totalAssets, old ? old.bs.totalAssets : 0)]
        );
        var equityRows = group('Equity', cur.bs.equity, old && old.bs.equity);
        equityRows.splice(equityRows.length - 1, 0, line('   Retained earnings', cur.bs.retainedEarnings, old ? old.bs.retainedEarnings : 0));
        equityRows[equityRows.length - 1] = line('Total equity', cur.bs.totalEquity, old ? old.bs.totalEquity : 0);
        var liabRows = [].concat(
            group('Non-current liabilities', cur.ncLiab, old && old.ncLiab),
            group('Current liabilities', cur.cLiab, old && old.cLiab),
            [line('TOTAL EQUITY AND LIABILITIES', cur.bs.totalEquity + cur.bs.totalLiabilities, old ? old.bs.totalEquity + old.bs.totalLiabilities : 0)]
        );
        return {
            title: 'Statement of Financial Position',
            summary: [
                ['Total assets', cur.bs.totalAssets],
                ['Total liabilities', cur.bs.totalLiabilities],
                ['Total equity', cur.bs.totalEquity],
                ['Balanced', cur.bs.balanced ? 'Yes' : 'No - check ledger'],
            ],
            sections: [
                { heading: 'Assets', head: head, rows: assetRows },
                { heading: 'Equity and liabilities', head: head, rows: equityRows.concat(liabRows) },
            ],
            notes: ['Assets and liabilities are split into current / non-current using account names and detail types (fixed assets, long-term loans, etc.).'],
        };
    }

    function rChangesInEquity(ctx, per) {
        var start = per.start || fyRange(per.fy, ctx.m0).start;
        var openAt = prevDay(start);
        var equityAccounts = (ctx.coa || []).filter(function (a) {
            return a.account_type === 'Equity';
        });
        var openBal = balancesAt(ctx, openAt);
        var closeBal = balancesAt(ctx, per.end);
        function bal(list, a) {
            var hit = list.find(function (x) { return String(x.id) === String(a.id); });
            return hit ? hit.balance : 0;
        }
        var cols = equityAccounts.filter(function (a) {
            return Math.abs(bal(openBal, a)) > 0.004 || Math.abs(bal(closeBal, a)) > 0.004;
        });
        var reOpen = profitAndLoss(ctx, null, openAt).netIncome;
        var profit = profitAndLoss(ctx, start, per.end).netIncome;
        var head = [''].concat(cols.map(function (a) { return a.account_name; }), ['Retained earnings', 'Total']);
        var openRow = ['Balance at ' + openAt].concat(cols.map(function (a) { return bal(openBal, a); }), [reOpen, sum(cols, function (a) { return bal(openBal, a); }) + reOpen]);
        var profitRow = ['Profit for the period'].concat(cols.map(function () { return ''; }), [profit, profit]);
        var moveRow = ['Capital introduced / (drawings) and other movements'].concat(cols.map(function (a) { return bal(closeBal, a) - bal(openBal, a); }), ['', sum(cols, function (a) { return bal(closeBal, a) - bal(openBal, a); })]);
        var closeRow = ['Balance at ' + per.end].concat(cols.map(function (a) { return bal(closeBal, a); }), [reOpen + profit, sum(cols, function (a) { return bal(closeBal, a); }) + reOpen + profit]);
        return {
            title: 'Statement of Changes in Equity',
            summary: [
                ['Opening equity', openRow[openRow.length - 1]],
                ['Profit for the period', profit],
                ['Closing equity', closeRow[closeRow.length - 1]],
            ],
            sections: [{ heading: 'For the period ' + per.label, head: head, rows: [openRow, profitRow, moveRow, closeRow] }],
            notes: [],
        };
    }

    function rCashFlow(ctx, per) {
        var start = per.start || fyRange(per.fy, ctx.m0).start;
        var openAt = prevDay(start);
        var openBal = balancesAt(ctx, openAt);
        var closeBal = balancesAt(ctx, per.end);
        var pl = profitAndLoss(ctx, start, per.end);
        var depreciation = sum(pl.opex.filter(function (l) { return DEPRECIATION.test(l.name + ' ' + l.detail); }), function (l) { return l.amount; });
        var keys = {};
        openBal.concat(closeBal).forEach(function (a) {
            keys[String(a.id || a.account_name)] = a;
        });
        function change(a) {
            var k = String(a.id || a.account_name);
            var o = openBal.find(function (x) { return String(x.id || x.account_name) === k; });
            var c = closeBal.find(function (x) { return String(x.id || x.account_name) === k; });
            return (c ? c.balance : 0) - (o ? o.balance : 0);
        }
        var working = [];
        var investing = 0;
        var financing = [];
        var cashOpen = 0;
        var cashClose = 0;
        Object.keys(keys).forEach(function (k) {
            var a = keys[k];
            var type = a.account_type;
            var text = acctText(a);
            var delta = change(a);
            if (type === 'Asset' && CASH_ACCOUNT.test(text) && !NON_CURRENT_ASSET.test(text)) {
                var o = openBal.find(function (x) { return String(x.id || x.account_name) === k; });
                var c = closeBal.find(function (x) { return String(x.id || x.account_name) === k; });
                cashOpen += o ? o.balance : 0;
                cashClose += c ? c.balance : 0;
                return;
            }
            if (Math.abs(delta) < 0.005) {
                return;
            }
            if (type === 'Asset' && NON_CURRENT_ASSET.test(text)) {
                investing -= delta;
            } else if (type === 'Asset') {
                working.push(['   (Increase) / decrease in ' + a.account_name, -delta]);
            } else if (type === 'Liability' && NON_CURRENT_LIABILITY.test(text)) {
                financing.push(['   Movement in ' + a.account_name, delta]);
            } else if (type === 'Liability') {
                working.push(['   Increase / (decrease) in ' + a.account_name, delta]);
            } else if (type === 'Equity') {
                financing.push(['   Capital introduced / (drawings) - ' + a.account_name, delta]);
            }
        });
        investing -= depreciation;
        var operating = pl.netIncome + depreciation + sum(working, function (r) { return r[1]; });
        var financingTotal = sum(financing, function (r) { return r[1]; });
        var netChange = operating + investing + financingTotal;
        var diff = cashClose - (cashOpen + netChange);
        var rows = [['Cash flows from operating activities', ''], ['   Profit before tax', pl.netIncome], ['   Adjustment: depreciation and amortisation', depreciation]]
            .concat(working, [['Net cash from operating activities', operating], ['Cash flows from investing activities', ''], ['   Purchase (net of disposals) of property, plant & equipment', investing], ['Net cash used in investing activities', investing], ['Cash flows from financing activities', '']])
            .concat(financing, [['Net cash from financing activities', financingTotal], ['Net increase / (decrease) in cash', netChange], ['Cash and cash equivalents at beginning', cashOpen], ['Cash and cash equivalents at end', cashOpen + netChange]]);
        var notes = ['Indirect method, derived from balance movements in the general ledger. Cash accounts are those named Cash / Bank / Petty Cash.'];
        if (Math.abs(diff) > 0.5) {
            notes.unshift('Unreconciled difference of ' + money(diff) + ' (check accounts with non-standard types).');
        }
        return {
            title: 'Statement of Cash Flows',
            summary: [
                ['Operating activities', operating],
                ['Investing activities', investing],
                ['Financing activities', financingTotal],
                ['Closing cash (per ledger)', cashClose],
            ],
            sections: [{ heading: 'For the period ' + per.label, head: ['', 'Amount'], rows: rows }],
            notes: notes,
        };
    }

    function rSalesRegister(ctx, per) {
        var sales = salesInPeriod(ctx, per);
        return {
            title: 'Sales Register',
            summary: [
                ['Invoices', String(sales.length)],
                ['Value excl. VAT', sum(sales, invoiceNet)],
                ['VAT', sum(sales, invoiceTax)],
                ['Total invoiced', sum(sales, function (i) { return i.total_amount; })],
            ],
            sections: [
                {
                    heading: 'Final invoices',
                    head: ['Date', 'Invoice No', 'Bill No', 'Customer', 'Function date', 'Tax invoice', 'Excl. VAT', 'VAT', 'Total'],
                    rows: sales.map(function (i) {
                        return [invoiceDate(i), i.invoice_number || '', i.bill_number || '', i.customer_name || '', day(i.event_date), isTaxInvoice(i) ? 'Yes' : 'No', invoiceNet(i), invoiceTax(i), num(i.total_amount)];
                    }),
                    foot: ['', '', '', '', '', 'Total', sum(sales, invoiceNet), sum(sales, invoiceTax), sum(sales, function (i) { return i.total_amount; })],
                },
            ],
            notes: ['Advance payment invoices are excluded to avoid double counting with the final invoice.'],
        };
    }

    function rPurchaseRegister(ctx, per) {
        var rows = expensesInPeriod(ctx, per);
        var pos = (ctx.purchaseOrders || []).filter(function (p) {
            return inRange(p.created_at, per.start, per.end) && !/cancel|reject/i.test(String(p.status || ''));
        });
        function supplierName(id) {
            var s = (ctx.suppliers || []).find(function (x) { return String(x.id) === String(id); });
            return s ? s.name : '';
        }
        return {
            title: 'Purchase Register',
            summary: [
                ['Expense entries', String(rows.length)],
                ['Value excl. VAT', sum(rows, function (e) { return num(e.amount) - num(e.vat_amount); })],
                ['Input VAT', sum(rows, function (e) { return e.vat_amount; })],
                ['WHT deducted', sum(rows, function (e) { return e.wht_amount; })],
                ['Purchase orders', sum(pos, function (p) { return p.total_amount; })],
            ],
            sections: [
                {
                    heading: 'Expenses and supplier bills',
                    head: ['Date', 'Reference', 'Supplier', 'TIN', 'Supplier invoice', 'Category', 'Excl. VAT', 'VAT', 'WHT', 'Total'],
                    rows: rows.map(function (e) {
                        return [day(e.expense_date), e.reference_no || '', e.payee || '', e.supplier_tin || '', e.supplier_invoice_no || '', e.category_account_name || '', num(e.amount) - num(e.vat_amount), num(e.vat_amount), num(e.wht_amount), num(e.amount)];
                    }),
                    foot: ['', '', '', '', '', 'Total', sum(rows, function (e) { return num(e.amount) - num(e.vat_amount); }), sum(rows, function (e) { return e.vat_amount; }), sum(rows, function (e) { return e.wht_amount; }), sum(rows, function (e) { return e.amount; })],
                },
                {
                    heading: 'Purchase orders',
                    head: ['Date', 'PO No', 'Supplier', 'Status', 'Amount'],
                    rows: pos.map(function (p) {
                        return [day(p.created_at), p.po_number || '', supplierName(p.supplier_id), p.status || '', num(p.total_amount)];
                    }),
                    foot: ['', '', '', 'Total', sum(pos, function (p) { return p.total_amount; })],
                },
            ],
            notes: [],
        };
    }

    function rFixedAssets(ctx, per) {
        var reg = assetRegister(ctx, per.fy);
        function total(k) {
            return sum(reg, function (r) { return r[k]; });
        }
        return {
            title: 'Fixed Asset Register',
            summary: [
                ['Assets', String(reg.length)],
                ['Cost', sum(reg, function (r) { return r.asset.cost; })],
                ['Depreciation for the year', total('depreciation')],
                ['Net book value', total('nbv')],
                ['Capital allowances (Y/A)', total('allowance')],
            ],
            sections: [
                {
                    heading: 'Accounting depreciation',
                    head: ['Code', 'Asset', 'Category', 'Acquired', 'Method', 'Cost', 'Dep. for year', 'Accumulated dep.', 'Net book value'],
                    rows: reg.map(function (r) {
                        return [r.asset.asset_code || '', r.asset.name + (r.disposed ? ' (disposed)' : ''), r.asset.category || '', day(r.asset.acquisition_date), r.asset.depreciation_method === 'reducing_balance' ? 'Reducing' : 'Straight line', num(r.asset.cost), r.depreciation, r.accumulated, r.nbv];
                    }),
                    foot: ['', 'Total', '', '', '', sum(reg, function (r) { return r.asset.cost; }), total('depreciation'), total('accumulated'), total('nbv')],
                },
                {
                    heading: 'Capital allowances (tax)',
                    head: ['Code', 'Asset', 'Cost', 'Rate', 'Allowance for Y/A', 'Tax written-down value'],
                    rows: reg.map(function (r) {
                        return [r.asset.asset_code || '', r.asset.name, num(r.asset.cost), num(r.asset.capital_allowance_rate) + '%', r.allowance, r.taxWdv];
                    }),
                    foot: ['', 'Total', sum(reg, function (r) { return r.asset.cost; }), '', total('allowance'), total('taxWdv')],
                },
            ],
            notes: ['Depreciation is monthly (pro-rata from the month of acquisition). Capital allowances are a full year from the year of assessment of acquisition. Balancing allowances / charges on disposal are not calculated.'],
        };
    }

    function rCashBook(ctx, per, ui) {
        var accounts = cashAccounts(ctx);
        var acctId = ui.accountId || (accounts[0] && accounts[0].id) || '';
        if (!acctId) {
            return { title: 'Cash Book', summary: [], sections: [], notes: ['No Cash / Bank accounts found in the Chart of Accounts.'] };
        }
        var led = glAccountLedger(ctx.journals, ctx.coa, acctId, per.start, per.end, ctx.vouchers);
        var receipts = sum(led.rows, function (r) { return r.debit; });
        var payments = sum(led.rows, function (r) { return r.credit; });
        return {
            title: 'Cash Book - ' + ((led.account && led.account.account_name) || ''),
            summary: [
                ['Opening balance', led.opening],
                ['Receipts', receipts],
                ['Payments', payments],
                ['Closing balance', led.closing],
            ],
            sections: [
                {
                    heading: 'Transactions',
                    head: ['Date', 'Reference', 'Description', 'Receipts', 'Payments', 'Balance'],
                    rows: [['', '', 'Opening balance', '', '', led.opening]].concat(led.rows.map(function (r) {
                        return [day(r.entry_date), r.reference_no || '', r.description || '', num(r.debit) || '', num(r.credit) || '', r.running];
                    })),
                    foot: ['', '', 'Totals / closing', receipts, payments, led.closing],
                },
            ],
            notes: [],
        };
    }

    function bankRecData(ctx, per, ui) {
        var accounts = cashAccounts(ctx);
        var acctId = ui.accountId || ((accounts.find(function (a) { return /bank/i.test(acctText(a)); }) || accounts[0] || {}).id) || '';
        if (!acctId) {
            return null;
        }
        var led = glAccountLedger(ctx.journals, ctx.coa, acctId, null, per.end, ctx.vouchers);
        var cleared = {};
        (ctx.clearings || []).forEach(function (c) {
            if (day(c.cleared_at) <= per.end) {
                cleared[String(c.journal_entry_id)] = c;
            }
        });
        var uncleared = led.rows.filter(function (r) {
            return !cleared[String(r.id)];
        });
        var deposits = uncleared.filter(function (r) { return num(r.debit) > 0; });
        var payments = uncleared.filter(function (r) { return num(r.credit) > 0; });
        var statement = num(ui.statementBalance);
        var adjusted = statement + sum(deposits, function (r) { return r.debit; }) - sum(payments, function (r) { return r.credit; });
        return { acctId: acctId, led: led, cleared: cleared, deposits: deposits, payments: payments, statement: statement, adjusted: adjusted, difference: adjusted - led.closing };
    }

    function rBankRec(ctx, per, ui) {
        var d = bankRecData(ctx, per, ui);
        if (!d) {
            return { title: 'Bank Reconciliation', summary: [], sections: [], notes: ['No Bank accounts found in the Chart of Accounts.'] };
        }
        return {
            title: 'Bank Reconciliation - ' + ((d.led.account && d.led.account.account_name) || ''),
            summary: [
                ['Balance per bank statement', d.statement],
                ['Add: deposits not yet credited', sum(d.deposits, function (r) { return r.debit; })],
                ['Less: unpresented payments', sum(d.payments, function (r) { return r.credit; })],
                ['Adjusted bank balance', d.adjusted],
                ['Balance per cash book', d.led.closing],
                ['Difference', d.difference],
            ],
            sections: [
                {
                    heading: 'Deposits not yet credited by the bank',
                    head: ['Date', 'Reference', 'Description', 'Amount'],
                    rows: d.deposits.map(function (r) { return [day(r.entry_date), r.reference_no || '', r.description || '', num(r.debit)]; }),
                    foot: ['', '', 'Total', sum(d.deposits, function (r) { return r.debit; })],
                },
                {
                    heading: 'Unpresented payments',
                    head: ['Date', 'Reference', 'Description', 'Amount'],
                    rows: d.payments.map(function (r) { return [day(r.entry_date), r.reference_no || '', r.description || '', num(r.credit)]; }),
                    foot: ['', '', 'Total', sum(d.payments, function (r) { return r.credit; })],
                },
            ],
            notes: Math.abs(d.difference) > 0.005 ? ['Difference is not zero. Tick the entries that appear on the bank statement, or check for bank charges not yet recorded.'] : ['Reconciled.'],
        };
    }

    var BUCKETS = ['0-30 days', '31-60 days', '61-90 days', '91-180 days', 'Over 180 days'];

    function bucketOf(days) {
        if (days <= 30) {
            return 0;
        }
        if (days <= 60) {
            return 1;
        }
        if (days <= 90) {
            return 2;
        }
        if (days <= 180) {
            return 3;
        }
        return 4;
    }

    function rAgedReceivables(ctx, per) {
        var asOf = per.end;
        var groups = {};
        function keyOf(fnId, bill) {
            return fnId ? 'fn:' + fnId : bill ? 'bill:' + String(bill).trim() : null;
        }
        (ctx.invoices || []).forEach(function (i) {
            if (!isFinalInvoice(i) || !invoiceDate(i) || invoiceDate(i) > asOf) {
                return;
            }
            var k = keyOf(i.function_id, i.bill_number) || 'inv:' + i.id;
            var g = groups[k] || (groups[k] = { customer: i.customer_name || '', ref: i.invoice_number || '', date: invoiceDate(i), billed: 0, paid: 0 });
            g.billed += num(i.total_amount);
            if (invoiceDate(i) < g.date) {
                g.date = invoiceDate(i);
            }
        });
        (ctx.payments || []).forEach(function (p) {
            if (isYes(p.is_refunded) || !day(p.bill_date) || day(p.bill_date) > asOf) {
                return;
            }
            var k = keyOf(p.function_id, p.bill_number);
            if (k && groups[k]) {
                groups[k].paid += num(p.bill_amount);
            }
        });
        var rows = Object.keys(groups)
            .map(function (k) {
                var g = groups[k];
                return Object.assign(g, { due: g.billed - g.paid, days: daysBetween(g.date, asOf) });
            })
            .filter(function (g) {
                return g.due > 0.005;
            })
            .sort(function (a, b) {
                return b.days - a.days;
            });
        var totals = [0, 0, 0, 0, 0];
        var tableRows = rows.map(function (g) {
            var cols = [0, 0, 0, 0, 0];
            cols[bucketOf(g.days)] = g.due;
            totals[bucketOf(g.days)] += g.due;
            return [g.customer, g.ref, g.date, String(g.days)].concat(cols, [g.due]);
        });
        var total = sum(rows, function (g) { return g.due; });
        return {
            title: 'Aged Receivables',
            summary: [['Customers with balances', String(rows.length)], ['Total receivable', total]].concat(BUCKETS.map(function (b, i) { return [b, totals[i]]; })),
            sections: [{ heading: 'Outstanding final invoices', head: ['Customer', 'Invoice', 'Invoice date', 'Days'].concat(BUCKETS, ['Balance']), rows: tableRows, foot: ['Total', '', '', ''].concat(totals, [total]) }],
            notes: ['Balance = final invoice value less payments received for the same function / bill number, as at the selected date.'],
        };
    }

    function rAgedPayables(ctx, per) {
        var asOf = per.end;
        var bySupplier = {};
        (ctx.purchaseOrders || []).forEach(function (p) {
            if (!day(p.created_at) || day(p.created_at) > asOf || /cancel|reject|draft/i.test(String(p.status || ''))) {
                return;
            }
            var s = bySupplier[p.supplier_id] || (bySupplier[p.supplier_id] = { orders: [], paid: 0 });
            s.orders.push({ date: day(p.created_at), ref: p.po_number || '', amount: num(p.total_amount) });
        });
        (ctx.supplierPayments || []).forEach(function (p) {
            if (!day(p.payment_date) || day(p.payment_date) > asOf || !bySupplier[p.supplier_id]) {
                return;
            }
            bySupplier[p.supplier_id].paid += num(p.amount);
        });
        var totals = [0, 0, 0, 0, 0];
        var rows = [];
        Object.keys(bySupplier).forEach(function (id) {
            var s = bySupplier[id];
            var remaining = s.paid;
            var cols = [0, 0, 0, 0, 0];
            s.orders.sort(function (a, b) { return a.date.localeCompare(b.date); }).forEach(function (o) {
                var applied = Math.min(remaining, o.amount);
                remaining -= applied;
                var open = o.amount - applied;
                if (open > 0.005) {
                    var b = bucketOf(daysBetween(o.date, asOf));
                    cols[b] += open;
                    totals[b] += open;
                }
            });
            var due = cols.reduce(function (a, b) { return a + b; }, 0);
            if (due > 0.005) {
                var sup = (ctx.suppliers || []).find(function (x) { return String(x.id) === String(id); });
                rows.push([sup ? sup.name : 'Supplier'].concat(cols, [due]));
            }
        });
        rows.sort(function (a, b) { return b[b.length - 1] - a[a.length - 1]; });
        var total = totals.reduce(function (a, b) { return a + b; }, 0);
        return {
            title: 'Aged Payables',
            summary: [['Suppliers with balances', String(rows.length)], ['Total payable', total]].concat(BUCKETS.map(function (b, i) { return [b, totals[i]]; })),
            sections: [{ heading: 'Outstanding purchase orders', head: ['Supplier'].concat(BUCKETS, ['Balance']), rows: rows, foot: ['Total'].concat(totals, [total]) }],
            notes: ['Supplier payments are applied to the oldest purchase orders first.'],
        };
    }

    function rProfile(ctx) {
        var s = ctx.settings;
        return {
            title: 'Statutory Profile',
            summary: [
                ['TIN', s.stat_tin || '-'],
                ['VAT registered', s.stat_vat_registered === '1' ? 'Yes - ' + (s.stat_vat_no || 'no number') : 'No'],
                ['SSCL registered', s.stat_sscl_registered === '1' ? 'Yes' : 'No'],
                ['TDL applicable', s.stat_tdl_applicable === '1' ? 'Yes' : 'No'],
                ['EPF employer no.', s.stat_epf_employer_no || '-'],
                ['Financial year starts', new Date(2000, fyStartMonth(s) - 1, 1).toLocaleString('en-US', { month: 'long' })],
            ],
            sections: [],
            notes: ['All rates are editable because Sri Lankan tax rates change often. Confirm the current rates with your accountant or IRD.'],
        };
    }

    var REPORTS = [
        { id: 'vat', group: 'Inland Revenue (IRD)', name: 'VAT Return', modes: ['quarter', 'month', 'custom'], build: rVat },
        { id: 'sscl', group: 'Inland Revenue (IRD)', name: 'SSCL Return', modes: ['quarter', 'month', 'custom'], build: rSscl },
        { id: 'apit', group: 'Inland Revenue (IRD)', name: 'APIT Monthly', modes: ['month'], build: rApitMonthly },
        { id: 'apit_annual', group: 'Inland Revenue (IRD)', name: 'APIT Annual + T-10', modes: ['fy'], build: rApitAnnual },
        { id: 'wht', group: 'Inland Revenue (IRD)', name: 'WHT Schedule + Certificates', modes: ['month', 'quarter', 'fy', 'custom'], build: rWht },
        { id: 'income_tax', group: 'Inland Revenue (IRD)', name: 'Income Tax Computation', modes: ['fy'], build: rIncomeTax, editor: 'incomeTax' },
        { id: 'tdl', group: 'Tourism (SLTDA)', name: 'Tourism Development Levy', modes: ['month', 'quarter', 'custom'], build: rTdl },
        { id: 'epf_c', group: 'EPF / ETF / Labour', name: 'EPF Form C (Monthly)', modes: ['month'], build: rEpfC },
        { id: 'epf_half', group: 'EPF / ETF / Labour', name: 'EPF Half-Yearly Summary', modes: ['half'], build: rEpfHalf },
        { id: 'etf_month', group: 'EPF / ETF / Labour', name: 'ETF Monthly', modes: ['month'], build: rEtfMonthly },
        { id: 'etf_form2', group: 'EPF / ETF / Labour', name: 'ETF Form II (Half-Yearly)', modes: ['half'], build: rEtfForm2 },
        { id: 'gratuity', group: 'EPF / ETF / Labour', name: 'Gratuity Liability', modes: ['asof'], build: rGratuity },
        { id: 'salary_register', group: 'EPF / ETF / Labour', name: 'Salary & Wage Register', modes: ['month'], build: rSalaryRegister },
        { id: 'pl', group: 'Financial Statements (SLFRS)', name: 'Profit or Loss', modes: ['fy', 'custom'], build: rProfitOrLoss },
        { id: 'sofp', group: 'Financial Statements (SLFRS)', name: 'Financial Position', modes: ['fy', 'asof'], build: rFinancialPosition },
        { id: 'soce', group: 'Financial Statements (SLFRS)', name: 'Changes in Equity', modes: ['fy', 'custom'], build: rChangesInEquity },
        { id: 'cashflow', group: 'Financial Statements (SLFRS)', name: 'Cash Flows', modes: ['fy', 'custom'], build: rCashFlow },
        { id: 'sales_register', group: 'Registers & Schedules', name: 'Sales Register', modes: ['month', 'quarter', 'fy', 'custom'], build: rSalesRegister },
        { id: 'purchase_register', group: 'Registers & Schedules', name: 'Purchase Register', modes: ['month', 'quarter', 'fy', 'custom'], build: rPurchaseRegister },
        { id: 'fixed_assets', group: 'Registers & Schedules', name: 'Fixed Asset Register', modes: ['fy'], build: rFixedAssets, editor: 'fixedAssets' },
        { id: 'cash_book', group: 'Registers & Schedules', name: 'Cash Book', modes: ['month', 'quarter', 'fy', 'custom'], build: rCashBook, editor: 'account' },
        { id: 'bank_rec', group: 'Registers & Schedules', name: 'Bank Reconciliation', modes: ['asof'], build: rBankRec, editor: 'bankRec' },
        { id: 'aged_receivables', group: 'Registers & Schedules', name: 'Aged Receivables', modes: ['asof'], build: rAgedReceivables },
        { id: 'aged_payables', group: 'Registers & Schedules', name: 'Aged Payables', modes: ['asof'], build: rAgedPayables },
        { id: 'profile', group: 'Setup', name: 'Statutory Profile & Rates', modes: [], build: rProfile, editor: 'profile' },
    ];

    /* ------------------------------------------------------------------ exports */

    function fmtCell(v) {
        if (v == null) {
            return '';
        }
        if (typeof v === 'number') {
            return v < 0 ? '(' + money(-v) + ')' : money(v);
        }
        return String(v);
    }

    function fileBase(doc, per) {
        return (doc.title + ' ' + (per.label || '')).replace(/[^A-Za-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 90);
    }

    function statLine(s) {
        return [
            s.stat_tin ? 'TIN: ' + s.stat_tin : '',
            s.stat_vat_registered === '1' && s.stat_vat_no ? 'VAT: ' + s.stat_vat_no : '',
            s.stat_business_reg_no ? 'BR: ' + s.stat_business_reg_no : '',
            s.stat_epf_employer_no ? 'EPF Reg: ' + s.stat_epf_employer_no : '',
        ].filter(Boolean).join('   |   ');
    }

    function newPdf(orientation) {
        if (!window.jspdf || !window.jspdf.jsPDF) {
            alert('The PDF library is still loading. Please try again in a moment.');
            return null;
        }
        return new window.jspdf.jsPDF({ orientation: orientation || 'portrait' });
    }

    function pdfHeader(pdf, title, subtitle, ctx) {
        var W = pdf.internal.pageSize.getWidth();
        var company = ctx.company || {};
        pdf.setTextColor(15, 23, 42);
        pdf.setFont('helvetica', 'bold');
        pdf.setFontSize(13);
        pdf.text(String(company.name || '').toUpperCase(), 14, 15);
        pdf.setFont('helvetica', 'normal');
        pdf.setFontSize(8);
        pdf.setTextColor(71, 85, 105);
        var y = 20;
        if (company.address) {
            pdf.text(String(company.address), 14, y);
            y += 4;
        }
        var sl = statLine(ctx.settings);
        if (sl) {
            pdf.text(sl, 14, y);
            y += 4;
        }
        pdf.setDrawColor(203, 213, 225);
        pdf.line(14, y + 1, W - 14, y + 1);
        y += 8;
        pdf.setTextColor(30, 41, 59);
        pdf.setFont('helvetica', 'bold');
        pdf.setFontSize(12);
        pdf.text(title.toUpperCase(), 14, y);
        pdf.setFont('helvetica', 'normal');
        pdf.setFontSize(8.5);
        pdf.text(subtitle || '', 14, y + 5);
        return y + 11;
    }

    function pdfFooter(pdf) {
        var n = pdf.internal.getNumberOfPages();
        var W = pdf.internal.pageSize.getWidth();
        var H = pdf.internal.pageSize.getHeight();
        for (var i = 1; i <= n; i++) {
            pdf.setPage(i);
            pdf.setFontSize(7);
            pdf.setTextColor(148, 163, 184);
            pdf.text('Generated ' + new Date().toLocaleString() + ' - BanquetDesk', 14, H - 8);
            pdf.text('Page ' + i + ' of ' + n, W - 14, H - 8, { align: 'right' });
        }
    }

    function numericColumns(section) {
        var styles = {};
        (section.head || []).forEach(function (_, idx) {
            if ((section.rows || []).some(function (r) { return typeof r[idx] === 'number'; })) {
                styles[idx] = { halign: 'right' };
            }
        });
        return styles;
    }

    function exportPdf(doc, per, ctx) {
        var wide = (doc.sections || []).some(function (s) { return (s.head || []).length > 7; });
        var pdf = newPdf(wide ? 'landscape' : 'portrait');
        if (!pdf) {
            return;
        }
        var H = pdf.internal.pageSize.getHeight();
        var y = pdfHeader(pdf, doc.title, per.label, ctx);
        if (doc.summary && doc.summary.length) {
            pdf.autoTable({
                startY: y,
                body: doc.summary.map(function (r) { return [r[0], fmtCell(r[1])]; }),
                theme: 'grid',
                styles: { fontSize: 8.5, cellPadding: 1.8 },
                columnStyles: { 0: { fontStyle: 'bold', cellWidth: 90 }, 1: { halign: 'right' } },
                tableWidth: 150,
                margin: { left: 14, right: 14 },
            });
            y = pdf.lastAutoTable.finalY + 7;
        }
        (doc.sections || []).forEach(function (s) {
            if (y > H - 35) {
                pdf.addPage();
                y = 18;
            }
            pdf.setFont('helvetica', 'bold');
            pdf.setFontSize(9.5);
            pdf.setTextColor(30, 41, 59);
            pdf.text(s.heading, 14, y);
            pdf.autoTable({
                startY: y + 2,
                head: [s.head],
                body: s.rows.length
                    ? s.rows.map(function (r) { return r.map(fmtCell); })
                    : [[{ content: 'No records for this period', colSpan: s.head.length, styles: { halign: 'center', textColor: [148, 163, 184] } }]],
                foot: s.foot ? [s.foot.map(fmtCell)] : undefined,
                showFoot: 'lastPage',
                theme: 'striped',
                headStyles: { fillColor: [30, 41, 59], fontSize: 7.5 },
                footStyles: { fillColor: [226, 232, 240], textColor: [15, 23, 42], fontStyle: 'bold', fontSize: 7.5 },
                styles: { fontSize: 7.5, cellPadding: 1.5 },
                columnStyles: numericColumns(s),
                margin: { left: 14, right: 14 },
            });
            y = pdf.lastAutoTable.finalY + 8;
        });
        if (doc.notes && doc.notes.length) {
            if (y > H - 30) {
                pdf.addPage();
                y = 18;
            }
            pdf.setFont('helvetica', 'italic');
            pdf.setFontSize(7.5);
            pdf.setTextColor(100, 116, 139);
            doc.notes.forEach(function (n) {
                var lines = pdf.splitTextToSize('* ' + n, pdf.internal.pageSize.getWidth() - 28);
                pdf.text(lines, 14, y);
                y += lines.length * 3.6 + 1;
            });
        }
        pdfFooter(pdf);
        pdf.save(fileBase(doc, per) + '.pdf');
    }

    function exportCsv(doc, per, ctx) {
        function q(v) {
            var s = v == null ? '' : typeof v === 'number' ? round2(v).toFixed(2) : String(v);
            return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
        }
        var lines = [];
        lines.push([q((ctx.company && ctx.company.name) || '')].join(','));
        lines.push(q(doc.title));
        lines.push(q(per.label || ''));
        lines.push('');
        (doc.summary || []).forEach(function (r) {
            lines.push([q(r[0]), q(r[1])].join(','));
        });
        (doc.sections || []).forEach(function (s) {
            lines.push('');
            lines.push(q(s.heading));
            lines.push(s.head.map(q).join(','));
            s.rows.forEach(function (r) { lines.push(r.map(q).join(',')); });
            if (s.foot) {
                lines.push(s.foot.map(q).join(','));
            }
        });
        var blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = fileBase(doc, per) + '.csv';
        document.body.appendChild(a);
        a.click();
        setTimeout(function () {
            URL.revokeObjectURL(a.href);
            a.remove();
        }, 500);
    }

    function signatureBlock(pdf, y, ctx) {
        var s = ctx.settings;
        pdf.setFont('helvetica', 'normal');
        pdf.setFontSize(8.5);
        pdf.setTextColor(30, 41, 59);
        pdf.line(14, y, 84, y);
        pdf.text(s.stat_signatory_name || 'Authorised signatory', 14, y + 5);
        if (s.stat_signatory_designation) {
            pdf.text(s.stat_signatory_designation, 14, y + 9);
        }
        pdf.text('Date: ' + todayStr(), 140, y + 5);
    }

    function exportT10(ctx, per, data) {
        if (!data.list.length) {
            alert('No payroll data for this year of assessment.');
            return;
        }
        var pdf = newPdf('portrait');
        if (!pdf) {
            return;
        }
        data.list.forEach(function (p, idx) {
            if (idx > 0) {
                pdf.addPage();
            }
            var y = pdfHeader(pdf, 'Certificate of Income Tax Deduction (T-10)', 'Advance Personal Income Tax - ' + per.label, ctx);
            pdf.autoTable({
                startY: y,
                body: [
                    ['Employer', (ctx.company && ctx.company.name) || ''],
                    ['Employer TIN', ctx.settings.stat_tin || '-'],
                    ['Employee', p.name],
                    ['Employee No', p.empNo || '-'],
                    ['NIC', p.nic || '-'],
                    ['Employee TIN', p.tin || '-'],
                ],
                theme: 'grid',
                styles: { fontSize: 9, cellPadding: 2 },
                columnStyles: { 0: { fontStyle: 'bold', cellWidth: 55 } },
                margin: { left: 14, right: 14 },
            });
            pdf.autoTable({
                startY: pdf.lastAutoTable.finalY + 6,
                head: [['Month', 'Gross remuneration', 'APIT deducted']],
                body: data.months.map(function (m) {
                    var v = p.months[m] || { gross: 0, apit: 0 };
                    return [shortMonth(m), money(v.gross), money(v.apit)];
                }),
                foot: [['Total', money(p.gross), money(p.apit)]],
                theme: 'striped',
                headStyles: { fillColor: [30, 41, 59] },
                footStyles: { fillColor: [226, 232, 240], textColor: [15, 23, 42], fontStyle: 'bold' },
                styles: { fontSize: 9 },
                columnStyles: { 1: { halign: 'right' }, 2: { halign: 'right' } },
                margin: { left: 14, right: 14 },
            });
            pdf.setFontSize(8.5);
            pdf.text('We certify that the above tax was deducted from the employee\'s remuneration and remitted to the Inland Revenue Department.', 14, pdf.lastAutoTable.finalY + 10, { maxWidth: 180 });
            signatureBlock(pdf, pdf.lastAutoTable.finalY + 32, ctx);
        });
        pdfFooter(pdf);
        pdf.save('T10_Certificates_' + fyLabel(per.fy, ctx.m0).replace('/', '-') + '.pdf');
    }

    function exportWhtCertificates(ctx, per, rows) {
        if (!rows.length) {
            alert('No payments with WHT in this period.');
            return;
        }
        var byPayee = {};
        rows.forEach(function (e) {
            var k = (e.payee || 'Payee') + '|' + (e.supplier_tin || '');
            (byPayee[k] = byPayee[k] || []).push(e);
        });
        var pdf = newPdf('portrait');
        if (!pdf) {
            return;
        }
        Object.keys(byPayee).forEach(function (k, idx) {
            var list = byPayee[k];
            if (idx > 0) {
                pdf.addPage();
            }
            var y = pdfHeader(pdf, 'Withholding Tax Certificate', per.label, ctx);
            pdf.autoTable({
                startY: y,
                body: [
                    ['Withholding agent', (ctx.company && ctx.company.name) || ''],
                    ['Agent TIN', ctx.settings.stat_tin || '-'],
                    ['Payee', list[0].payee || ''],
                    ['Payee TIN', list[0].supplier_tin || '-'],
                ],
                theme: 'grid',
                styles: { fontSize: 9, cellPadding: 2 },
                columnStyles: { 0: { fontStyle: 'bold', cellWidth: 55 } },
                margin: { left: 14, right: 14 },
            });
            pdf.autoTable({
                startY: pdf.lastAutoTable.finalY + 6,
                head: [['Date', 'Reference', 'Payment type', 'Gross amount', 'WHT deducted']],
                body: list.map(function (e) {
                    return [day(e.expense_date), e.reference_no || '', e.wht_type || 'Other', money(e.amount), money(e.wht_amount)];
                }),
                foot: [['', '', 'Total', money(sum(list, function (e) { return e.amount; })), money(sum(list, function (e) { return e.wht_amount; }))]],
                theme: 'striped',
                headStyles: { fillColor: [30, 41, 59] },
                footStyles: { fillColor: [226, 232, 240], textColor: [15, 23, 42], fontStyle: 'bold' },
                styles: { fontSize: 9 },
                columnStyles: { 3: { halign: 'right' }, 4: { halign: 'right' } },
                margin: { left: 14, right: 14 },
            });
            pdf.setFontSize(8.5);
            pdf.text('We certify that the above tax was withheld and remitted to the Inland Revenue Department.', 14, pdf.lastAutoTable.finalY + 10, { maxWidth: 180 });
            signatureBlock(pdf, pdf.lastAutoTable.finalY + 32, ctx);
        });
        pdfFooter(pdf);
        pdf.save('WHT_Certificates_' + fileBase({ title: '' }, per) + '.pdf');
    }

    /* ------------------------------------------------------------------ UI */

    var inputCls = 'w-full p-2.5 border-2 border-slate-200 rounded-xl font-bold text-xs bg-white outline-none focus:border-indigo-500';
    var labelCls = 'text-[9px] font-black uppercase text-slate-500 ml-1';
    var btnCls = 'px-4 py-2.5 rounded-xl text-[10px] font-black uppercase shadow-sm transition-all';

    function Field(props) {
        return h('div', { className: props.className || '' }, h('label', { className: labelCls }, props.label), props.children);
    }

    function TextInput(props) {
        return h('input', {
            type: props.type || 'text',
            step: props.step,
            value: props.value == null ? '' : props.value,
            placeholder: props.placeholder || '',
            onChange: function (e) {
                props.onChange(e.target.value);
            },
            className: inputCls,
        });
    }

    function Toggle(props) {
        return h('label', { className: 'flex items-center gap-2 cursor-pointer select-none mt-5' },
            h('input', {
                type: 'checkbox',
                className: 'w-4 h-4',
                checked: props.value === '1',
                onChange: function (e) {
                    props.onChange(e.target.checked ? '1' : '0');
                },
            }),
            h('span', { className: 'text-xs font-black text-slate-700' }, props.label));
    }

    function PeriodPicker(props) {
        var p = props.period;
        var modes = props.modes;
        var mode = props.mode;
        var m0 = props.m0;
        var labels = { month: 'Month', quarter: 'Quarter', half: 'Half-year', fy: 'Year of assessment', asof: 'As at date', custom: 'Custom' };
        function set(patch) {
            props.onChange(Object.assign({}, p, patch));
        }
        var thisYear = new Date().getFullYear();
        var years = [];
        for (var y = thisYear + 1; y >= thisYear - 8; y--) {
            years.push(y);
        }
        var controls = [];
        if (mode === 'month') {
            controls.push(h('input', { key: 'm', type: 'month', value: p.month, onChange: function (e) { set({ month: e.target.value }); }, className: inputCls + ' w-44' }));
        }
        if (mode === 'quarter' || mode === 'fy') {
            controls.push(h('select', { key: 'fy', value: p.fy, onChange: function (e) { set({ fy: +e.target.value }); }, className: inputCls + ' w-40' },
                years.map(function (y2) { return h('option', { key: y2, value: y2 }, 'Y/A ' + fyLabel(y2, m0)); })));
        }
        if (mode === 'quarter') {
            controls.push(h('select', { key: 'q', value: p.q, onChange: function (e) { set({ q: +e.target.value }); }, className: inputCls + ' w-32' },
                [1, 2, 3, 4].map(function (q) { return h('option', { key: q, value: q }, 'Quarter ' + q); })));
        }
        if (mode === 'half') {
            controls.push(h('select', { key: 'y', value: p.year, onChange: function (e) { set({ year: +e.target.value }); }, className: inputCls + ' w-28' },
                years.map(function (y2) { return h('option', { key: y2, value: y2 }, y2); })));
            controls.push(h('select', { key: 'h', value: p.half, onChange: function (e) { set({ half: +e.target.value }); }, className: inputCls + ' w-40' },
                h('option', { value: 1 }, 'January - June'), h('option', { value: 2 }, 'July - December')));
        }
        if (mode === 'asof') {
            controls.push(h('input', { key: 'a', type: 'date', value: p.asof, onChange: function (e) { set({ asof: e.target.value }); }, className: inputCls + ' w-44' }));
        }
        if (mode === 'custom') {
            controls.push(h('input', { key: 's', type: 'date', value: p.start, onChange: function (e) { set({ start: e.target.value }); }, className: inputCls + ' w-40' }));
            controls.push(h('input', { key: 'e', type: 'date', value: p.end, onChange: function (e) { set({ end: e.target.value }); }, className: inputCls + ' w-40' }));
        }
        return h('div', { className: 'flex flex-wrap items-center gap-2' },
            modes.length > 1 ? h('div', { className: 'flex bg-slate-100 p-1 rounded-xl' },
                modes.map(function (m) {
                    return h('button', {
                        key: m,
                        type: 'button',
                        onClick: function () { props.onMode(m); },
                        className: 'px-3 py-1.5 rounded-lg text-[10px] font-black uppercase ' + (m === mode ? 'bg-white shadow text-indigo-900' : 'text-slate-500'),
                    }, labels[m]);
                })) : null,
            controls);
    }

    function DocView(props) {
        var doc = props.doc;
        if (doc.error) {
            return h('div', { className: 'p-6 bg-red-50 text-red-700 rounded-2xl text-sm font-bold' }, 'Could not build this report: ' + doc.error);
        }
        return h('div', { className: 'space-y-6' },
            doc.summary && doc.summary.length ? h('div', { className: 'grid grid-cols-2 md:grid-cols-4 gap-3' },
                doc.summary.map(function (r, i) {
                    return h('div', { key: i, className: 'p-4 rounded-2xl bg-slate-50 border border-slate-100' },
                        h('p', { className: 'text-[9px] font-black uppercase text-slate-400' }, r[0]),
                        h('p', { className: 'text-sm font-black mt-1 ' + (typeof r[1] === 'number' && r[1] < 0 ? 'text-red-600' : 'text-slate-900') }, typeof r[1] === 'number' ? 'LKR ' + fmtCell(r[1]) : r[1]));
                })) : null,
            doc.notes && doc.notes.length ? h('div', { className: 'p-4 rounded-2xl bg-amber-50 border border-amber-100 space-y-1' },
                doc.notes.map(function (n, i) {
                    return h('p', { key: i, className: 'text-[11px] font-bold text-amber-800' }, n);
                })) : null,
            (doc.sections || []).map(function (s, si) {
                var numeric = numericColumns(s);
                return h('div', { key: si, className: 'rounded-2xl border border-slate-100 overflow-hidden' },
                    h('p', { className: 'px-4 py-3 bg-slate-50 text-[10px] font-black uppercase tracking-wider text-slate-600' }, s.heading),
                    h('div', { className: 'overflow-x-auto' },
                        h('table', { className: 'w-full text-left border-collapse text-xs' },
                            h('thead', { className: 'bg-slate-900 text-white text-[9px] font-black uppercase' },
                                h('tr', null, s.head.map(function (c, i) {
                                    return h('th', { key: i, className: 'p-3 whitespace-nowrap ' + (numeric[i] ? 'text-right' : '') }, c);
                                }))),
                            h('tbody', null,
                                s.rows.length ? s.rows.map(function (r, ri) {
                                    var isHeading = r.slice(1).every(function (c) { return c === ''; }) && String(r[0]).indexOf('   ') !== 0;
                                    return h('tr', { key: ri, className: 'border-b border-slate-50 ' + (isHeading ? 'bg-slate-50/60' : '') },
                                        r.map(function (c, ci) {
                                            return h('td', { key: ci, className: 'p-3 ' + (typeof c === 'number' ? 'text-right font-bold ' + (c < 0 ? 'text-red-600' : 'text-slate-800') : isHeading ? 'font-black text-slate-700' : 'text-slate-600') + (ci === 0 ? ' whitespace-pre' : '') }, fmtCell(c));
                                        }));
                                }) : h('tr', null, h('td', { colSpan: s.head.length, className: 'p-6 text-center text-slate-400 font-bold' }, 'No records for this period'))),
                            s.foot ? h('tfoot', null, h('tr', { className: 'bg-slate-100 font-black' }, s.foot.map(function (c, i) {
                                return h('td', { key: i, className: 'p-3 ' + (typeof c === 'number' ? 'text-right' : '') }, fmtCell(c));
                            }))) : null)));
            }));
    }

    var PROFILE_FIELDS = [
        { key: 'stat_tin', label: 'Company TIN' },
        { key: 'stat_business_reg_no', label: 'Business registration no.' },
        { key: 'stat_vat_registered', label: 'VAT registered', toggle: true },
        { key: 'stat_vat_no', label: 'VAT registration no.' },
        { key: 'stat_vat_rate', label: 'VAT rate %', type: 'number' },
        { key: 'stat_sscl_registered', label: 'SSCL registered', toggle: true },
        { key: 'stat_sscl_rate', label: 'SSCL rate %', type: 'number' },
        { key: 'stat_sscl_liable_pct', label: 'SSCL liable turnover %', type: 'number' },
        { key: 'stat_tdl_applicable', label: 'Tourism Development Levy applies', toggle: true },
        { key: 'stat_tdl_rate', label: 'TDL rate %', type: 'number' },
        { key: 'stat_epf_employer_no', label: 'EPF employer registration no.' },
        { key: 'stat_etf_employer_no', label: 'ETF employer registration no.' },
        { key: 'stat_income_tax_rate', label: 'Corporate income tax rate %', type: 'number' },
        { key: 'stat_fy_start_month', label: 'Financial year start month (1-12)', type: 'number' },
        { key: 'stat_gratuity_min_years', label: 'Gratuity eligibility (years)', type: 'number' },
        { key: 'stat_gratuity_months_per_year', label: 'Gratuity months per year of service', type: 'number' },
        { key: 'stat_signatory_name', label: 'Certificate signatory name' },
        { key: 'stat_signatory_designation', label: 'Signatory designation' },
    ];

    function ProfileEditor(props) {
        var _a = useState(function () { return Object.assign({}, props.settings); });
        var form = _a[0];
        var setForm = _a[1];
        function set(key, value) {
            var o = {};
            o[key] = value;
            setForm(Object.assign({}, form, o));
        }
        return h('div', { className: 'p-5 rounded-2xl border-2 border-indigo-100 bg-indigo-50/40 space-y-4' },
            h('div', { className: 'grid grid-cols-1 md:grid-cols-3 gap-4' },
                PROFILE_FIELDS.map(function (f) {
                    if (f.toggle) {
                        return h(Toggle, { key: f.key, label: f.label, value: form[f.key], onChange: function (v) { set(f.key, v); } });
                    }
                    return h(Field, { key: f.key, label: f.label }, h(TextInput, {
                        type: f.type,
                        step: f.type === 'number' ? '0.01' : undefined,
                        value: form[f.key],
                        onChange: function (v) { set(f.key, v); },
                    }));
                })),
            props.canEdit ? h('div', { className: 'flex justify-end' },
                h('button', {
                    type: 'button',
                    onClick: function () {
                        var values = {};
                        PROFILE_FIELDS.forEach(function (f) { values[f.key] = String(form[f.key] == null ? '' : form[f.key]).trim(); });
                        props.onSave(values);
                    },
                    className: btnCls + ' bg-indigo-600 text-white hover:bg-indigo-700',
                }, 'Save statutory profile')) : null);
    }

    function IncomeTaxEditor(props) {
        var saved = parseJson(props.settings['stat_it_adj_' + props.per.fy]);
        var adj = Object.assign({ disallowed: '', exempt: '', otherDeductions: '', lossesBf: '', whtCredits: '', instalmentsPaid: '' }, saved, props.ui.adj || {});
        var fields = [
            ['disallowed', 'Disallowed expenses (entertainment, penalties, etc.)'],
            ['exempt', 'Exempt / separately taxed income'],
            ['otherDeductions', 'Other allowable deductions'],
            ['lossesBf', 'Tax losses brought forward'],
            ['whtCredits', 'WHT credits (tax deducted by customers)'],
            ['instalmentsPaid', 'Instalments already paid'],
        ];
        return h('div', { className: 'p-5 rounded-2xl border-2 border-indigo-100 bg-indigo-50/40 space-y-4' },
            h('div', { className: 'grid grid-cols-1 md:grid-cols-3 gap-4' },
                fields.map(function (f) {
                    return h(Field, { key: f[0], label: f[1] }, h(TextInput, {
                        type: 'number',
                        step: '0.01',
                        value: adj[f[0]],
                        onChange: function (v) {
                            var next = Object.assign({}, adj);
                            next[f[0]] = v;
                            props.setUi({ adj: next });
                        },
                    }));
                })),
            props.canEdit ? h('div', { className: 'flex justify-end' },
                h('button', {
                    type: 'button',
                    onClick: function () {
                        var values = {};
                        values['stat_it_adj_' + props.per.fy] = JSON.stringify(adj);
                        props.onSave(values);
                    },
                    className: btnCls + ' bg-indigo-600 text-white hover:bg-indigo-700',
                }, 'Save adjustments for Y/A ' + fyLabel(props.per.fy, props.m0))) : null);
    }

    function blankAsset() {
        return { id: null, asset_code: '', name: '', category: '', acquisition_date: todayStr(), cost: '', residual_value: '0', useful_life_years: '5', depreciation_method: 'straight_line', capital_allowance_rate: '20', disposal_date: '', disposal_value: '', notes: '' };
    }

    function FixedAssetEditor(props) {
        var _a = useState(null);
        var form = _a[0];
        var setForm = _a[1];
        var _b = useState(false);
        var busy = _b[0];
        var setBusy = _b[1];
        function set(k, v) {
            var o = {};
            o[k] = v;
            setForm(Object.assign({}, form, o));
        }
        async function save() {
            if (!String(form.name || '').trim()) {
                return alert('Asset name is required');
            }
            if (!(num(form.cost) > 0)) {
                return alert('Enter the asset cost');
            }
            var row = {
                asset_code: String(form.asset_code || '').trim() || null,
                name: String(form.name).trim(),
                category: String(form.category || '').trim() || null,
                acquisition_date: form.acquisition_date || null,
                cost: num(form.cost),
                residual_value: num(form.residual_value),
                useful_life_years: num(form.useful_life_years) || 5,
                depreciation_method: form.depreciation_method || 'straight_line',
                capital_allowance_rate: num(form.capital_allowance_rate),
                disposal_date: form.disposal_date || null,
                disposal_value: form.disposal_value === '' || form.disposal_value == null ? null : num(form.disposal_value),
                notes: form.notes || null,
                status: form.disposal_date ? 'Disposed' : 'Active',
            };
            setBusy(true);
            try {
                var res = form.id
                    ? await props.supabase.from('fixed_assets').update(row).eq('id', form.id)
                    : await props.supabase.from('fixed_assets').insert([Object.assign({ id: uuid() }, row)]);
                if (res.error) {
                    throw res.error;
                }
                await props.reload();
                setForm(null);
            } catch (err) {
                alert('Could not save asset: ' + ((err && err.message) || err));
            } finally {
                setBusy(false);
            }
        }
        async function remove(a) {
            if (!confirm('Delete asset "' + a.name + '"?')) {
                return;
            }
            var res = await props.supabase.from('fixed_assets').delete().eq('id', a.id);
            if (res.error) {
                return alert('Could not delete asset: ' + (res.error.message || res.error));
            }
            props.reload();
        }
        var list = props.assets || [];
        return h('div', { className: 'p-5 rounded-2xl border-2 border-indigo-100 bg-indigo-50/40 space-y-4' },
            h('div', { className: 'flex items-center justify-between' },
                h('p', { className: 'text-[10px] font-black uppercase text-indigo-800' }, 'Assets (' + list.length + ')'),
                props.canEdit && !form ? h('button', { type: 'button', onClick: function () { setForm(blankAsset()); }, className: btnCls + ' bg-indigo-600 text-white' }, '+ Add asset') : null),
            form ? h('div', { className: 'grid grid-cols-1 md:grid-cols-4 gap-3' },
                h(Field, { label: 'Asset code' }, h(TextInput, { value: form.asset_code, onChange: function (v) { set('asset_code', v); } })),
                h(Field, { label: 'Asset name *', className: 'md:col-span-2' }, h(TextInput, { value: form.name, onChange: function (v) { set('name', v); } })),
                h(Field, { label: 'Category' }, h(TextInput, { value: form.category, placeholder: 'Furniture, Machinery...', onChange: function (v) { set('category', v); } })),
                h(Field, { label: 'Acquisition date' }, h(TextInput, { type: 'date', value: form.acquisition_date, onChange: function (v) { set('acquisition_date', v); } })),
                h(Field, { label: 'Cost (LKR) *' }, h(TextInput, { type: 'number', step: '0.01', value: form.cost, onChange: function (v) { set('cost', v); } })),
                h(Field, { label: 'Residual value' }, h(TextInput, { type: 'number', step: '0.01', value: form.residual_value, onChange: function (v) { set('residual_value', v); } })),
                h(Field, { label: 'Useful life (years)' }, h(TextInput, { type: 'number', step: '0.5', value: form.useful_life_years, onChange: function (v) { set('useful_life_years', v); } })),
                h(Field, { label: 'Depreciation method' }, h('select', { value: form.depreciation_method, onChange: function (e) { set('depreciation_method', e.target.value); }, className: inputCls },
                    h('option', { value: 'straight_line' }, 'Straight line'), h('option', { value: 'reducing_balance' }, 'Reducing balance'))),
                h(Field, { label: 'Capital allowance rate % p.a.' }, h(TextInput, { type: 'number', step: '0.01', value: form.capital_allowance_rate, onChange: function (v) { set('capital_allowance_rate', v); } })),
                h(Field, { label: 'Disposal date' }, h(TextInput, { type: 'date', value: form.disposal_date, onChange: function (v) { set('disposal_date', v); } })),
                h(Field, { label: 'Disposal proceeds' }, h(TextInput, { type: 'number', step: '0.01', value: form.disposal_value, onChange: function (v) { set('disposal_value', v); } })),
                h(Field, { label: 'Notes', className: 'md:col-span-4' }, h(TextInput, { value: form.notes, onChange: function (v) { set('notes', v); } })),
                h('div', { className: 'md:col-span-4 flex justify-end gap-2' },
                    h('button', { type: 'button', onClick: function () { setForm(null); }, className: btnCls + ' text-slate-500' }, 'Cancel'),
                    h('button', { type: 'button', disabled: busy, onClick: save, className: btnCls + ' bg-indigo-600 text-white' }, busy ? 'Saving...' : form.id ? 'Save changes' : 'Add asset'))) : null,
            list.length ? h('div', { className: 'flex flex-wrap gap-2' },
                list.map(function (a) {
                    return h('div', { key: a.id, className: 'flex items-center gap-2 px-3 py-2 bg-white rounded-xl border border-slate-200 text-[11px] font-bold' },
                        h('span', null, (a.asset_code ? a.asset_code + ' - ' : '') + a.name),
                        props.canEdit ? h('button', {
                            type: 'button',
                            onClick: function () {
                                setForm(Object.assign(blankAsset(), a, { acquisition_date: day(a.acquisition_date), disposal_date: day(a.disposal_date), disposal_value: a.disposal_value == null ? '' : a.disposal_value }));
                            },
                            className: 'text-indigo-600',
                        }, 'Edit') : null,
                        props.canEdit ? h('button', { type: 'button', onClick: function () { remove(a); }, className: 'text-red-500' }, 'Delete') : null);
                })) : null);
    }

    function AccountSelect(props) {
        var accounts = cashAccounts(props.ctx);
        var value = props.ui.accountId || (props.preferBank ? (accounts.find(function (a) { return /bank/i.test(acctText(a)); }) || accounts[0] || {}).id : (accounts[0] || {}).id) || '';
        return h(Field, { label: 'Account', className: 'w-72' },
            h('select', { value: value, onChange: function (e) { props.setUi({ accountId: e.target.value }); }, className: inputCls },
                accounts.map(function (a) { return h('option', { key: a.id, value: a.id }, (a.account_code ? a.account_code + ' - ' : '') + a.account_name); })));
    }

    function BankRecEditor(props) {
        var d = bankRecData(props.ctx, props.per, props.ui);
        var _a = useState(null);
        var busyId = _a[0];
        var setBusyId = _a[1];
        if (!d) {
            return null;
        }
        async function toggle(row) {
            var existing = (props.ctx.clearings || []).find(function (c) { return String(c.journal_entry_id) === String(row.id); });
            setBusyId(row.id);
            try {
                var res = existing
                    ? await props.supabase.from('bank_clearings').delete().eq('id', existing.id)
                    : await props.supabase.from('bank_clearings').insert([{ id: uuid(), journal_entry_id: String(row.id), cleared_at: props.per.end, statement_ref: props.ui.statementRef || null }]);
                if (res.error) {
                    throw res.error;
                }
                await props.reload();
            } catch (err) {
                alert('Could not update: ' + ((err && err.message) || err));
            } finally {
                setBusyId(null);
            }
        }
        var rows = d.led.rows.slice().reverse().slice(0, 400);
        return h('div', { className: 'p-5 rounded-2xl border-2 border-indigo-100 bg-indigo-50/40 space-y-4' },
            h('div', { className: 'flex flex-wrap gap-3 items-end' },
                h(AccountSelect, { ctx: props.ctx, ui: props.ui, setUi: props.setUi, preferBank: true }),
                h(Field, { label: 'Balance per bank statement', className: 'w-56' }, h(TextInput, { type: 'number', step: '0.01', value: props.ui.statementBalance, onChange: function (v) { props.setUi({ statementBalance: v }); } })),
                h(Field, { label: 'Statement reference', className: 'w-48' }, h(TextInput, { value: props.ui.statementRef, onChange: function (v) { props.setUi({ statementRef: v }); } }))),
            h('p', { className: 'text-[10px] font-black uppercase text-slate-500' }, 'Tick entries that appear on the bank statement up to ' + props.per.end),
            h('div', { className: 'max-h-80 overflow-y-auto rounded-xl border border-slate-200 bg-white' },
                h('table', { className: 'w-full text-xs' },
                    h('tbody', null, rows.map(function (r) {
                        var isCleared = !!d.cleared[String(r.id)];
                        return h('tr', { key: r.id, className: 'border-b border-slate-50 ' + (isCleared ? 'bg-emerald-50/50' : '') },
                            h('td', { className: 'p-2 w-8' }, h('input', { type: 'checkbox', checked: isCleared, disabled: !props.canEdit || busyId === r.id, onChange: function () { toggle(r); } })),
                            h('td', { className: 'p-2 whitespace-nowrap' }, day(r.entry_date)),
                            h('td', { className: 'p-2' }, r.reference_no || ''),
                            h('td', { className: 'p-2 text-slate-500' }, r.description || ''),
                            h('td', { className: 'p-2 text-right font-bold text-emerald-700' }, num(r.debit) ? money(r.debit) : ''),
                            h('td', { className: 'p-2 text-right font-bold text-red-600' }, num(r.credit) ? money(r.credit) : ''));
                    })))));
    }

    function Panel(props) {
        var supabaseClient = props.supabase;
        var _s = useState(function () {
            var fromApp = {};
            Object.keys(props.settings || {}).forEach(function (k) {
                if (k.indexOf('stat_') === 0) {
                    fromApp[k] = props.settings[k];
                }
            });
            return Object.assign({}, DEFAULTS, fromApp, settingsCache);
        });
        var settings = _s[0];
        var setSettingsState = _s[1];
        var _f = useState([]);
        var fixedAssets = _f[0];
        var setFixedAssets = _f[1];
        var _c = useState([]);
        var clearings = _c[0];
        var setClearings = _c[1];
        var _r = useState('vat');
        var reportId = _r[0];
        var setReportId = _r[1];
        var _u = useState({});
        var uiAll = _u[0];
        var setUiAll = _u[1];
        var m0 = fyStartMonth(settings);
        var today = todayStr();
        var _p = useState(function () {
            var fy = fyOf(today, m0);
            var monthOfFy = (+today.slice(5, 7) - m0 + 12) % 12;
            return { mode: 'quarter', month: today.slice(0, 7), fy: fy, q: Math.floor(monthOfFy / 3) + 1, year: +today.slice(0, 4), half: +today.slice(5, 7) <= 6 ? 1 : 2, start: today.slice(0, 8) + '01', end: today, asof: today };
        });
        var period = _p[0];
        var setPeriod = _p[1];

        async function reloadExtra() {
            var results = await Promise.all([
                supabaseClient.from('fixed_assets').select('*').order('acquisition_date', { ascending: true }),
                supabaseClient.from('bank_clearings').select('*').order('cleared_at', { ascending: false }),
            ]);
            setFixedAssets((results[0] && results[0].data) || []);
            setClearings((results[1] && results[1].data) || []);
        }

        useEffect(function () {
            reloadExtra();
        }, []);

        async function saveSettings(values) {
            var rows = Object.keys(values).map(function (k) {
                return { key: k, value: values[k] };
            });
            var res = await supabaseClient.from('system_settings').upsert(rows, { onConflict: 'key' });
            if (res && res.error) {
                alert('Could not save: ' + (res.error.message || res.error));
                return;
            }
            Object.assign(settingsCache, values);
            setSettingsState(Object.assign({}, settings, values));
            alert('Saved');
        }

        var report = REPORTS.find(function (r) { return r.id === reportId; }) || REPORTS[0];
        var mode = report.modes.indexOf(period.mode) >= 0 ? period.mode : report.modes[0] || 'asof';
        var per = computePeriod(Object.assign({}, period, { mode: mode }), m0);
        var ui = uiAll[report.id] || {};
        function setUi(patch) {
            var next = Object.assign({}, uiAll);
            next[report.id] = Object.assign({}, ui, patch);
            setUiAll(next);
        }
        var ctx = {
            coa: props.coa || [],
            journals: props.journals || [],
            vouchers: props.vouchers || [],
            expenses: props.expenses || [],
            invoices: props.invoices || [],
            payments: props.payments || [],
            functions: props.functions || [],
            suppliers: props.suppliers || [],
            purchaseOrders: props.purchaseOrders || [],
            supplierPayments: props.supplierPayments || [],
            employees: props.employees || [],
            payrollRuns: props.payrollRuns || [],
            payrollSlips: props.payrollSlips || [],
            fixedAssets: fixedAssets,
            clearings: clearings,
            settings: settings,
            company: props.company || {},
            m0: m0,
        };
        var doc;
        try {
            if (!glReady()) {
                throw new Error('General ledger helpers are not available.');
            }
            doc = report.build(ctx, per, ui);
        } catch (err) {
            doc = { title: report.name, error: String((err && err.message) || err) };
        }

        var groups = [];
        REPORTS.forEach(function (r) {
            if (groups.indexOf(r.group) < 0) {
                groups.push(r.group);
            }
        });

        var editor = null;
        var editorProps = { ctx: ctx, per: per, ui: ui, setUi: setUi, settings: settings, supabase: supabaseClient, reload: reloadExtra, canEdit: !!props.canEdit, onSave: saveSettings, m0: m0 };
        if (report.editor === 'profile') {
            editor = h(ProfileEditor, Object.assign({ key: 'profile' }, editorProps));
        } else if (report.editor === 'incomeTax') {
            editor = h(IncomeTaxEditor, Object.assign({ key: 'it-' + per.fy }, editorProps));
        } else if (report.editor === 'fixedAssets') {
            editor = h(FixedAssetEditor, Object.assign({ assets: fixedAssets }, editorProps));
        } else if (report.editor === 'account') {
            editor = h('div', { className: 'flex' }, h(AccountSelect, editorProps));
        } else if (report.editor === 'bankRec') {
            editor = h(BankRecEditor, editorProps);
        }

        return h('div', { className: 'grid grid-cols-1 lg:grid-cols-[250px_1fr] gap-6' },
            h('aside', { className: 'bg-white rounded-3xl border border-slate-100 shadow-sm p-4 space-y-4 h-fit' },
                groups.map(function (g) {
                    return h('div', { key: g },
                        h('p', { className: 'text-[9px] font-black uppercase tracking-widest text-slate-400 mb-1.5 px-2' }, g),
                        REPORTS.filter(function (r) { return r.group === g; }).map(function (r) {
                            return h('button', {
                                key: r.id,
                                type: 'button',
                                onClick: function () { setReportId(r.id); },
                                className: 'w-full text-left px-3 py-2 rounded-xl text-[11px] font-bold transition-all ' + (r.id === report.id ? 'bg-indigo-600 text-white shadow' : 'text-slate-600 hover:bg-slate-50'),
                            }, r.name);
                        }));
                })),
            h('section', { className: 'bg-white rounded-3xl border border-slate-100 shadow-sm p-6 space-y-5 min-w-0' },
                h('div', { className: 'flex flex-wrap items-start justify-between gap-4' },
                    h('div', null,
                        h('p', { className: 'text-[10px] font-black uppercase tracking-widest text-indigo-500' }, report.group),
                        h('h3', { className: 'text-xl font-black text-slate-900' }, doc.title || report.name),
                        report.modes.length ? h('p', { className: 'text-xs font-bold text-slate-500 mt-0.5' }, per.label) : null),
                    report.modes.length ? h('div', { className: 'flex flex-wrap gap-2' },
                        (doc.actions || []).map(function (a, i) {
                            return h('button', { key: i, type: 'button', onClick: a.run, className: btnCls + ' bg-emerald-600 text-white hover:bg-emerald-700' }, a.label);
                        }),
                        h('button', { type: 'button', onClick: function () { exportPdf(doc, per, ctx); }, disabled: !!doc.error, className: btnCls + ' bg-slate-900 text-white hover:bg-slate-700' }, 'PDF'),
                        h('button', { type: 'button', onClick: function () { exportCsv(doc, per, ctx); }, disabled: !!doc.error, className: btnCls + ' bg-white border-2 border-slate-200 text-slate-700 hover:border-slate-400' }, 'CSV / Excel')) : null),
                report.modes.length ? h(PeriodPicker, {
                    period: period,
                    modes: report.modes,
                    mode: mode,
                    m0: m0,
                    onChange: setPeriod,
                    onMode: function (m) { setPeriod(Object.assign({}, period, { mode: m })); },
                }) : null,
                editor,
                h(DocView, { doc: doc })));
    }

    window.BanquetStatutory = {
        Panel: Panel,
        setSettings: setSettings,
        settings: currentSettings,
        invoiceTaxLine: invoiceTaxLine,
        whtTypes: function () {
            return WHT_TYPES.slice();
        },
    };
})();
