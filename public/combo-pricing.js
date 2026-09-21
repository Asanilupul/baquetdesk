/**
 * BanquetDesk combo package tier pricing (pax anchors + extra plates).
 * Loaded before banquetdesk.app.js — exposes window.BanquetComboPricing
 */
(function (global) {
  const React = global.React;
  const TIERS = [50, 100, 150, 200, 250, 300];

  function uid() {
    if (global.crypto && crypto.randomUUID) return crypto.randomUUID();
    return "opt_" + Date.now().toString(36) + "_" + Math.random().toString(36).slice(2, 9);
  }

  function num(v, d = 0) {
    const n = parseFloat(v);
    return Number.isFinite(n) ? n : d;
  }

  function emptyTiers() {
    const t = {};
    TIERS.forEach((p) => {
      t[String(p)] = 0;
    });
    return t;
  }

  function emptyOption(menu) {
    return {
      id: uid(),
      menu_id: menu?.id || "",
      menu_name: menu?.name || "",
      extra_plate_price: 0,
      tiers: emptyTiers(),
      vendor_package_ids: [],
      bite_lines: [],
      softdrink_lines: [],
    };
  }

  function normalizeOption(opt) {
    const tiers = emptyTiers();
    const src = opt?.tiers || {};
    TIERS.forEach((p) => {
      const k = String(p);
      tiers[k] = num(src[k] ?? src[p], 0);
    });
    return {
      id: opt?.id || uid(),
      menu_id: opt?.menu_id || "",
      menu_name: opt?.menu_name || "",
      extra_plate_price: num(opt?.extra_plate_price, 0),
      tiers,
      vendor_package_ids: Array.isArray(opt?.vendor_package_ids) ? opt.vendor_package_ids : [],
      bite_lines: Array.isArray(opt?.bite_lines) ? opt.bite_lines : [],
      softdrink_lines: Array.isArray(opt?.softdrink_lines) ? opt.softdrink_lines : [],
    };
  }

  /** Legacy combo (single menu_id) → one menu option so old data still works. */
  function normalizeCombo(combo) {
    if (!combo) return combo;
    let options = Array.isArray(combo.menu_options) ? combo.menu_options.map(normalizeOption) : [];
    if (!options.length && combo.menu_id) {
      const tierTotal = num(combo.price, 0);
      const tiers = emptyTiers();
      // Seed all anchors with legacy package total as a starting point (editable later)
      TIERS.forEach((p) => {
        tiers[String(p)] = tierTotal;
      });
      options = [
        normalizeOption({
          id: "legacy_" + (combo.id || "x"),
          menu_id: combo.menu_id,
          menu_name: combo.function_menu || combo.name || "",
          extra_plate_price: 0,
          tiers,
          vendor_package_ids: combo.vendor_package_ids || [],
          bite_lines: combo.bite_lines || [],
          softdrink_lines: combo.softdrink_lines || [],
        }),
      ];
    }
    return { ...combo, menu_options: options };
  }

  function lowerAnchor(pax) {
    let lower = null;
    for (const t of TIERS) {
      if (t <= pax) lower = t;
    }
    return lower;
  }

  /**
   * @returns {{ok:boolean,error?:string,pax:number,anchor:number|null,tierPrice:number,extraPlateQty:number,extraPlateRate:number,extraPlateTotal:number,baseTotal:number}}
   */
  function calculate(option, paxRaw) {
    const opt = normalizeOption(option || {});
    const pax = parseInt(paxRaw, 10) || 0;
    if (pax < 50) {
      return {
        ok: false,
        error: "Minimum 50 pax required for combo package pricing.",
        pax,
        anchor: null,
        tierPrice: 0,
        extraPlateQty: 0,
        extraPlateRate: num(opt.extra_plate_price),
        extraPlateTotal: 0,
        baseTotal: 0,
      };
    }

    const rate = num(opt.extra_plate_price);
    const exact = TIERS.includes(pax);

    if (exact) {
      const tierPrice = num(opt.tiers[String(pax)]);
      return {
        ok: true,
        pax,
        anchor: pax,
        tierPrice,
        extraPlateQty: 0,
        extraPlateRate: rate,
        extraPlateTotal: 0,
        baseTotal: tierPrice,
      };
    }

    if (pax > 300) {
      const tierPrice = num(opt.tiers["300"]);
      const extraPlateQty = pax - 300;
      const extraPlateTotal = extraPlateQty * rate;
      return {
        ok: true,
        pax,
        anchor: 300,
        tierPrice,
        extraPlateQty,
        extraPlateRate: rate,
        extraPlateTotal,
        baseTotal: tierPrice + extraPlateTotal,
      };
    }

    const anchor = lowerAnchor(pax);
    const tierPrice = num(opt.tiers[String(anchor)]);
    const extraPlateQty = pax - anchor;
    const extraPlateTotal = extraPlateQty * rate;
    return {
      ok: true,
      pax,
      anchor,
      tierPrice,
      extraPlateQty,
      extraPlateRate: rate,
      extraPlateTotal,
      baseTotal: tierPrice + extraPlateTotal,
    };
  }

  function money(n) {
    return "LKR " + Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
  }

  function includedLabels(option, catalogs) {
    const opt = normalizeOption(option);
    const vendors = catalogs?.vendorPackages || [];
    const extras = catalogs?.menuExtras || [];
    const names = [];
    if (opt.menu_name) names.push({ type: "Menu", name: opt.menu_name });
    (opt.vendor_package_ids || []).forEach((id) => {
      const v = vendors.find((x) => x.id === id);
      names.push({ type: "Vendor", name: v?.name || v?.package_name || id });
    });
    (opt.bite_lines || []).forEach((line) => {
      const x = extras.find((e) => e.id === line.extra_id);
      names.push({
        type: "Bite",
        name: (x?.name || line.extra_id) + (line.quantity ? ` × ${line.quantity}` : ""),
      });
    });
    (opt.softdrink_lines || []).forEach((line) => {
      const x = extras.find((e) => e.id === line.extra_id);
      names.push({
        type: "Soft drink",
        name: (x?.name || line.extra_id) + (line.quantity ? ` × ${line.quantity}` : ""),
      });
    });
    return names;
  }

  /**
   * optionalExtras: [{kind:'bite'|'softdrink'|'vendor', id, name, quantity, unit_price}]
   * bites/softdrinks => per_unit; vendors => flat
   */
  function buildBill(option, pax, optionalExtras, catalogs) {
    const calc = calculate(option, pax);
    const included = includedLabels(option, catalogs);
    const optLines = [];
    let optionalTotal = 0;
    (optionalExtras || []).forEach((ex) => {
      const kind = ex.kind || "bite";
      const qty = kind === "vendor" ? 1 : num(ex.quantity, 1);
      const rate = num(ex.unit_price);
      const lineTotal = qty * rate;
      optionalTotal += lineTotal;
      optLines.push({
        kind,
        id: ex.id || "",
        name: ex.name || kind,
        quantity: qty,
        unit_price: rate,
        rate_type: kind === "vendor" ? "flat" : "per_unit",
        line_total: lineTotal,
      });
    });

    const lines = [];
    if (calc.ok) {
      lines.push({
        key: "tier",
        category: "Combo Package",
        description: `${option?.menu_name || "Menu"} — ${calc.anchor} pax package`,
        quantity: 1,
        rate: calc.tierPrice,
        amount: calc.tierPrice,
      });
      if (calc.extraPlateQty > 0) {
        lines.push({
          key: "extra_plates",
          category: "Extra Plates",
          description: `Extra plates — ${calc.extraPlateQty} pax × ${money(calc.extraPlateRate)}`,
          quantity: calc.extraPlateQty,
          rate: calc.extraPlateRate,
          amount: calc.extraPlateTotal,
        });
      }
    }
    optLines.forEach((ex, i) => {
      lines.push({
        key: "opt_" + i,
        category: ex.kind === "vendor" ? "Extra Vendor" : ex.kind === "softdrink" ? "Extra Soft Drink" : "Extra Bite",
        description: ex.name,
        quantity: ex.quantity,
        rate: ex.unit_price,
        amount: ex.line_total,
      });
    });

    const grandTotal = (calc.ok ? calc.baseTotal : 0) + optionalTotal;

    return {
      calc,
      included,
      optionalLines: optLines,
      invoiceItems: lines.map((l) => ({
        category: l.category,
        description: l.description,
        quantity: l.quantity,
        rate: l.rate,
      })),
      baseTotal: calc.ok ? calc.baseTotal : 0,
      optionalTotal,
      grandTotal,
      /** per-pax rate for legacy menu_price field */
      perPersonRate: calc.ok && calc.pax > 0 ? calc.baseTotal / calc.pax : 0,
      snapshot: {
        version: 1,
        combo_menu_option_id: option?.id || null,
        menu_id: option?.menu_id || null,
        menu_name: option?.menu_name || "",
        pax: calc.pax,
        anchor: calc.anchor,
        tier_price: calc.tierPrice,
        extra_plate_qty: calc.extraPlateQty,
        extra_plate_rate: calc.extraPlateRate,
        extra_plate_total: calc.extraPlateTotal,
        base_total: calc.ok ? calc.baseTotal : 0,
        included,
        optional_extras: optLines,
        optional_total: optionalTotal,
        grand_total: grandTotal,
        locked_at: new Date().toISOString(),
      },
    };
  }

  function el(type, props, ...children) {
    return React.createElement(type, props, ...children.filter((c) => c !== false && c !== null && c !== undefined));
  }

  function renderBillPreview(bill) {
    if (!bill) return null;
    const calc = bill.calc || {};
    return el(
      "div",
      { className: "rounded-2xl border-2 border-indigo-100 bg-indigo-50/40 p-4 space-y-3 text-sm" },
      el("p", { className: "text-[10px] font-black uppercase tracking-widest text-indigo-500" }, "Bill preview"),
      !calc.ok && el("p", { className: "text-rose-600 font-bold text-xs" }, calc.error || "Invalid pax"),
      calc.ok &&
        el(
          "div",
          { className: "space-y-2" },
          el("p", { className: "font-black text-slate-800 text-xs uppercase" }, "Base package"),
          el(
            "div",
            { className: "flex justify-between gap-4 font-bold text-slate-700" },
            el("span", null, `${calc.anchor} pax package`),
            el("span", null, money(calc.tierPrice))
          ),
          (bill.included || []).length > 0 &&
            el(
              "ul",
              { className: "text-[11px] text-slate-500 space-y-0.5 pl-3 list-disc" },
              ...bill.included.map((inc, i) =>
                el("li", { key: i }, `${inc.type}: ${inc.name}`)
              )
            ),
          calc.extraPlateQty > 0 &&
            el(
              "div",
              { className: "flex justify-between gap-4 font-bold text-amber-800 border-t border-amber-200/60 pt-2" },
              el("span", null, `Extra plates (${calc.extraPlateQty} × ${money(calc.extraPlateRate)})`),
              el("span", null, money(calc.extraPlateTotal))
            ),
          (bill.optionalLines || []).length > 0 &&
            el(
              "div",
              { className: "space-y-1 border-t border-indigo-100 pt-2" },
              el("p", { className: "font-black text-slate-800 text-xs uppercase" }, "Optional extras"),
              ...bill.optionalLines.map((ex, i) =>
                el(
                  "div",
                  { key: i, className: "flex justify-between gap-4 text-slate-700 font-bold" },
                  el("span", null, ex.name + (ex.rate_type === "per_unit" ? ` × ${ex.quantity}` : " (flat)")),
                  el("span", null, money(ex.line_total))
                )
              )
            ),
          el(
            "div",
            { className: "flex justify-between gap-4 font-black text-indigo-900 border-t-2 border-indigo-200 pt-2 text-base" },
            el("span", null, "Total"),
            el("span", null, money(bill.grandTotal))
          )
        )
    );
  }

  /**
   * Compact React editor for menu_options on a combo form.
   * props: { options, menus, vendorPackages, biteExtras, drinkExtras, onChange }
   */
  function renderMenuOptionsEditor(props) {
    const options = (props.options || []).map(normalizeOption);
    const menus = props.menus || [];
    const vendorPackages = props.vendorPackages || [];
    const biteExtras = props.biteExtras || [];
    const drinkExtras = props.drinkExtras || [];
    const onChange = props.onChange || (() => {});

    const setOptions = (next) => onChange(next.map(normalizeOption));

    const updateAt = (idx, patch) => {
      const next = options.map((o, i) => (i === idx ? normalizeOption({ ...o, ...patch }) : o));
      setOptions(next);
    };

    const addOption = () => {
      const menu = menus[0];
      setOptions([...options, emptyOption(menu || null)]);
    };

    const removeOption = (idx) => setOptions(options.filter((_, i) => i !== idx));

    return el(
      "div",
      { className: "md:col-span-2 space-y-4 border-2 border-dashed border-indigo-200 rounded-3xl p-5 bg-slate-50/80" },
      el(
        "div",
        { className: "flex flex-wrap items-center justify-between gap-3" },
        el(
          "div",
          null,
          el("p", { className: "text-[10px] font-black uppercase tracking-widest text-indigo-600" }, "Menu options & pax tiers"),
          el(
            "p",
            { className: "text-[11px] text-slate-500 font-bold mt-1" },
            "Each menu has prices for 50/100/150/200/250/300 pax + extra plate. Between tiers: lower price + extra plates. Vendors/bites listed here are included in those tier totals."
          )
        ),
        el(
          "button",
          {
            type: "button",
            onClick: addOption,
            className: "px-4 py-2 rounded-xl bg-indigo-600 text-white text-[10px] font-black uppercase shadow",
          },
          "Add menu option"
        )
      ),
      options.length === 0 &&
        el("p", { className: "text-xs font-bold text-amber-700 bg-amber-50 border border-amber-200 rounded-xl p-3" }, "Add at least one menu option with tier prices."),
      ...options.map((opt, idx) =>
        el(
          "div",
          { key: opt.id, className: "bg-white rounded-2xl border border-slate-200 p-4 space-y-4 shadow-sm" },
          el(
            "div",
            { className: "flex flex-wrap gap-3 items-end" },
            el(
              "div",
              { className: "flex-1 min-w-[180px] flex flex-col gap-1" },
              el("label", { className: "text-[9px] font-black uppercase text-slate-400" }, "Menu"),
              el(
                "select",
                {
                  className: "p-3 border-2 rounded-xl font-bold text-sm",
                  value: opt.menu_id,
                  onChange: (e) => {
                    const m = menus.find((x) => x.id === e.target.value);
                    updateAt(idx, { menu_id: e.target.value, menu_name: m?.name || "" });
                  },
                },
                el("option", { value: "" }, "Select menu…"),
                ...menus.map((m) => el("option", { key: m.id, value: m.id }, m.name))
              )
            ),
            el(
              "div",
              { className: "w-40 flex flex-col gap-1" },
              el("label", { className: "text-[9px] font-black uppercase text-slate-400" }, "Extra plate (LKR)"),
              el("input", {
                type: "number",
                className: "p-3 border-2 rounded-xl font-bold text-sm",
                value: opt.extra_plate_price,
                onChange: (e) => updateAt(idx, { extra_plate_price: e.target.value }),
              })
            ),
            el(
              "button",
              {
                type: "button",
                onClick: () => removeOption(idx),
                className: "px-3 py-3 rounded-xl bg-rose-50 text-rose-600 text-[10px] font-black uppercase border border-rose-200",
              },
              "Remove"
            )
          ),
          el(
            "div",
            { className: "grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2" },
            ...TIERS.map((p) =>
              el(
                "div",
                { key: p, className: "flex flex-col gap-1" },
                el("label", { className: "text-[9px] font-black uppercase text-slate-400" }, `${p} pax`),
                el("input", {
                  type: "number",
                  className: "p-2.5 border-2 rounded-xl font-bold text-sm",
                  value: opt.tiers[String(p)] ?? 0,
                  onChange: (e) =>
                    updateAt(idx, {
                      tiers: { ...opt.tiers, [String(p)]: e.target.value },
                    }),
                })
              )
            )
          ),
          el(
            "div",
            { className: "grid grid-cols-1 md:grid-cols-3 gap-3" },
            el(
              "div",
              { className: "flex flex-col gap-1" },
              el("label", { className: "text-[9px] font-black uppercase text-slate-400" }, "Included vendor packages"),
              el(
                "select",
                {
                  multiple: true,
                  className: "p-2 border-2 rounded-xl font-bold text-xs h-28",
                  value: opt.vendor_package_ids,
                  onChange: (e) =>
                    updateAt(idx, {
                      vendor_package_ids: Array.from(e.target.selectedOptions).map((o) => o.value),
                    }),
                },
                ...vendorPackages.map((v) =>
                  el("option", { key: v.id, value: v.id }, v.name || v.package_name || v.id)
                )
              )
            ),
            el(
              "div",
              { className: "flex flex-col gap-1" },
              el("label", { className: "text-[9px] font-black uppercase text-slate-400" }, "Included bites (hold Ctrl)"),
              el(
                "select",
                {
                  multiple: true,
                  className: "p-2 border-2 rounded-xl font-bold text-xs h-28",
                  value: (opt.bite_lines || []).map((l) => l.extra_id),
                  onChange: (e) => {
                    const ids = Array.from(e.target.selectedOptions).map((o) => o.value);
                    updateAt(idx, {
                      bite_lines: ids.map((id) => {
                        const prev = (opt.bite_lines || []).find((l) => l.extra_id === id);
                        const master = biteExtras.find((x) => x.id === id);
                        return {
                          extra_id: id,
                          quantity: prev?.quantity || 1,
                          unit_price: prev?.unit_price ?? num(master?.price),
                        };
                      }),
                    });
                  },
                },
                ...biteExtras.map((b) => el("option", { key: b.id, value: b.id }, b.name))
              )
            ),
            el(
              "div",
              { className: "flex flex-col gap-1" },
              el("label", { className: "text-[9px] font-black uppercase text-slate-400" }, "Included soft drinks"),
              el(
                "select",
                {
                  multiple: true,
                  className: "p-2 border-2 rounded-xl font-bold text-xs h-28",
                  value: (opt.softdrink_lines || []).map((l) => l.extra_id),
                  onChange: (e) => {
                    const ids = Array.from(e.target.selectedOptions).map((o) => o.value);
                    updateAt(idx, {
                      softdrink_lines: ids.map((id) => {
                        const prev = (opt.softdrink_lines || []).find((l) => l.extra_id === id);
                        const master = drinkExtras.find((x) => x.id === id);
                        return {
                          extra_id: id,
                          quantity: prev?.quantity || 1,
                          unit_price: prev?.unit_price ?? num(master?.price),
                        };
                      }),
                    });
                  },
                },
                ...drinkExtras.map((b) => el("option", { key: b.id, value: b.id }, b.name))
              )
            )
          )
        )
      )
    );
  }

  global.BanquetComboPricing = {
    TIERS,
    uid,
    emptyOption,
    normalizeOption,
    normalizeCombo,
    calculate,
    buildBill,
    includedLabels,
    money,
    renderBillPreview,
    renderMenuOptionsEditor,
  };
})(typeof window !== "undefined" ? window : globalThis);
