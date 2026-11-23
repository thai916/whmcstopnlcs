<style>
.wtp{max-width:1400px;margin:0 auto;color:#243247;font-family:inherit}
.wtp-hero{background:linear-gradient(120deg,#10243c 0%,#1b3b5f 58%,#22567e 100%);color:#fff;border-radius:12px;padding:24px 26px;margin-bottom:20px;box-shadow:0 8px 24px rgba(16,36,60,.16)}
.wtp-hero h1{margin:0 0 6px;font-size:24px;font-weight:700;letter-spacing:.2px;color:#fff!important;border-bottom:0!important;padding-bottom:0!important}
.wtp-hero p{margin:0;color:#cbdcef;font-size:14px;max-width:900px;line-height:1.55}
.wtp-chips{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
.wtp-chip{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:20px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);font-size:12px}
.wtp-steps{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 20px;padding:12px 14px;background:#fff;border:1px solid #e3e9f0;border-radius:10px}
.wtp-step{display:flex;align-items:center;gap:7px;padding:6px 11px;border-radius:18px;background:#f3f6fa;color:#6b7d92;font-size:12px;font-weight:600}
.wtp-step--on{background:#1d6fb8;color:#fff}
.wtp-step--done{background:#e5f4ec;color:#1c7a45}
.wtp-step i{font-size:11px}
.wtp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:18px;margin-bottom:18px}
.wtp-card{background:#fff;border:1px solid #e3e9f0;border-radius:11px;overflow:hidden;box-shadow:0 2px 8px rgba(16,36,60,.04);margin-bottom:18px}
.wtp-card__head{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid #edf1f6;background:#fbfcfe}
.wtp-card__head h2{margin:0;font-size:15px;font-weight:700;color:#1c3350}
.wtp-card__icon{width:30px;height:30px;border-radius:8px;background:#e8f1fb;color:#1d6fb8;display:flex;align-items:center;justify-content:center}
.wtp-card__aside{margin-left:auto;font-size:12px;color:#6b7d92}
.wtp-card__body{padding:18px}
.wtp-section{font-size:11px;text-transform:uppercase;letter-spacing:.9px;color:#8394a8;font-weight:700;margin:4px 0 10px}
.wtp-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-bottom:16px}
.wtp-field{display:flex;flex-direction:column;gap:5px;min-width:0}
.wtp-field label{font-size:12px;font-weight:600;color:#40556e}
.wtp-field input,.wtp-field select,.wtp-field textarea{width:100%;padding:8px 10px;border:1px solid #d5dee9;border-radius:7px;font-size:13px;background:#fff;color:#243247}
.wtp-field input:focus,.wtp-field select:focus{outline:2px solid #b9d6f2;border-color:#1d6fb8}
.wtp-span2{grid-column:span 2}
.wtp-actions{display:flex;flex-wrap:wrap;gap:9px;margin-top:6px}
.wtp-note{border-radius:8px;padding:10px 13px;font-size:12px;line-height:1.5;margin:0 0 12px}
.wtp-note--warn{background:#fff7e8;border:1px solid #f6d79d;color:#855500}
.wtp-note--info{background:#eef6fd;border:1px solid #c7dff4;color:#1b527f}
.wtp-note--ok{background:#eaf7ef;border:1px solid #bce3cd;color:#1c6b3f}
.wtp-table{width:100%;border-collapse:collapse;font-size:12px}
.wtp-table th{text-align:left;padding:9px 12px;background:#f7f9fc;color:#5f738b;font-weight:700;border-bottom:1px solid #e3e9f0;white-space:nowrap}
.wtp-table td{padding:9px 12px;border-bottom:1px solid #eef2f7;vertical-align:top}
.wtp-table tr:last-child td{border-bottom:0}
.wtp-tag{display:inline-block;padding:3px 8px;border-radius:5px;background:#eef3f8;color:#365170;font-size:11px;font-family:monospace}
.wtp-pill{display:inline-block;padding:3px 9px;border-radius:12px;font-size:11px;font-weight:700}
.wtp-pill--gray{background:#eef1f5;color:#64758a}
.wtp-pill--green{background:#e4f5ea;color:#1c7a45}
.wtp-pill--blue{background:#e5f0fb;color:#1d6fb8}
.wtp-pill--red{background:#fdeaea;color:#b4232a}
.wtp-pill--amber{background:#fff3dc;color:#946200}
.wtp-empty{padding:24px;text-align:center;color:#8a99aa;font-size:13px}
.wtp-kv{display:grid;grid-template-columns:210px 1fr;gap:7px 12px;font-size:12px;margin:0}
.wtp-kv dt{color:#73859a;font-weight:600}
.wtp-kv dd{margin:0;color:#243247;word-break:break-word}
.wtp-bar{height:10px;border-radius:6px;background:#e8eef5;overflow:hidden;margin:8px 0 6px}
.wtp-bar span{display:block;height:100%;background:linear-gradient(90deg,#1d6fb8,#3fa88a);transition:width .3s}
.wtp-mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px;word-break:break-all}
.wtp-conn{display:flex;align-items:center;gap:11px;padding:11px 0;border-bottom:1px solid #eef2f7}
.wtp-conn:last-child{border-bottom:0}
.wtp-conn__body{flex:1;min-width:0}
.wtp-conn__name{font-size:13px;font-weight:700;color:#1c3350}
.wtp-conn__meta{font-size:11px;color:#8394a8;margin-top:2px}
@media(max-width:720px){.wtp-grid{grid-template-columns:1fr}.wtp-fields{grid-template-columns:1fr}.wtp-span2{grid-column:span 1}.wtp-kv{grid-template-columns:1fr}}
</style>
