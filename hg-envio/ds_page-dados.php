<?php
/**
 * Template Name: Dados Datastore 2026
 */
$dts_slug = get_post_field( 'post_name', get_the_ID() );
?>
<?php $GLOBALS['dts_body_class'] = 'pg-dados'; get_header(); ?>
<style type="text/css">
	.st0{fill:#FFFFFF;}
	.st1{fill:#335DA8;}
	.st2{fill:#B7A73E;}
	.st3{fill:#CE3F5A;}
	.st4{fill:#8C539B;}
	.st5{fill:#CBCB3A;}
	.st6{fill:#5FB352;}
	.st7{fill:#D2633C;}
</style>
<style>





:root {
  --black:#0A0A0A; --coal:#111; --charcoal:#1A1A1A; --mid:#242424;
  --smoke:#363636; --dim:#666; --silver:#888; --mist:#B8B8B8;
  --white:#EFEFED; --pure:#FFF;
  --blue:#335DA8; --olive:#B7A73E; --rose:#CE3F5A; --purple:#8C539B;
  --lime:#CBCB3A; --green:#5FB352; --orange:#D2633C;
  --f:'Helvetica Neue','HN',Helvetica,Arial,sans-serif;
  --ease:cubic-bezier(0.16,1,0.3,1);
  --r:8px; --r-md:14px; --r-lg:20px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body.pg-dados{background:var(--black);color:var(--white);font-family:var(--f);font-weight:400;-webkit-font-smoothing:antialiased;overflow-x:hidden}
img{display:block;max-width:100%}
a{text-decoration:none;color:inherit}
button{font-family:var(--f);cursor:pointer;border:none;background:none}


/* ── NAV ── */
nav{position:fixed;top:0;left:0;right:0;z-index:200;height:64px;display:flex;align-items:center;justify-content:space-between;padding:0 48px;background:rgba(10,10,10,0.88);backdrop-filter:blur(18px);border-bottom:1px solid rgba(255,255,255,0.05);transition:background .3s}
nav.solid{background:rgba(10,10,10,0.97)}
.nav-logo{display:flex;align-items:center;height:40px}
.nav-logo svg{height:40px;width:auto}
.nav-links{display:flex;gap:32px;list-style:none}
.nav-links .nav-link{font-weight:400;font-size:14px;letter-spacing:0.02em;color:var(--white);transition:color .2s;position:relative;padding-bottom:4px}
.nav-links .nav-link:hover{color:var(--pure)}
.nav-links .nav-link.ativo{color:var(--pure)}
.nav-links .nav-link.ativo::after{content:'';position:absolute;bottom:-4px;left:0;right:0;height:2px;border-radius:1px;background:var(--olive)}
.nav-right{display:flex;align-items:center;gap:6px}
.btn-succ{display:inline-flex;align-items:center;gap:6px;font-weight:700;font-size:13px;letter-spacing:0.01em;color:#fff;background:#B7A73E;padding:8px 14px;border-radius:var(--r);text-decoration:none;transition:all .2s}
.btn-ghost{font-weight:400;font-size:13px;letter-spacing:0.02em;color:var(--mist);border:1px solid rgba(255,255,255,0.15);padding:7px 13px;border-radius:var(--r);transition:all .2s;cursor:pointer;font-family:var(--f);background:none;text-decoration:none;display:inline-flex;align-items:center;gap:5px}
.btn-ghost:hover{border-color:rgba(255,255,255,0.4);color:var(--pure)}
.btn-solid{font-weight:700;font-size:13px;letter-spacing:0.01em;color:#fff;background:var(--green);padding:8px 16px;border-radius:var(--r);transition:background .2s,color .2s;cursor:pointer;font-family:var(--f);border:none;text-decoration:none;display:inline-flex;align-items:center}
.btn-solid:hover{background:#4a9942;color:#fff}
.btn-ghost.ativo{background:var(--pure);color:var(--black);border-color:var(--pure)}
.btn-ghost.ativo:hover{background:var(--pure);color:var(--black)}
.btn-solid:hover{background:var(--pure)}

/* ── MOBILE MENU ── */
.mob-bar{display:none;position:fixed;top:0;left:0;right:0;z-index:200;height:56px;background:rgba(10,10,10,0.95);backdrop-filter:blur(18px);border-bottom:1px solid rgba(255,255,255,0.06);align-items:center;justify-content:space-between;padding:0 18px}
.mob-logo-icon{height:34px;width:auto}
.mob-actions{display:flex;align-items:center;gap:8px}
.mob-icon-btn{width:36px;height:36px;display:flex;align-items:center;justify-content:center;border:1px solid rgba(255,255,255,0.12);border-radius:var(--r);background:none;cursor:pointer;color:var(--mist)}
.mob-hamburger{width:36px;height:36px;display:flex;align-items:center;justify-content:center;border:1px solid rgba(255,255,255,0.15);border-radius:var(--r);background:none;cursor:pointer}
.mob-hamburger span{display:block;width:16px;height:1.5px;background:var(--white);transition:all .3s}
.mob-hamburger span+span{margin-top:4px}
.mob-drawer{position:fixed;top:0;right:-100%;width:88%;max-width:340px;height:100vh;background:#fff;z-index:300;transition:right .38s cubic-bezier(0.16,1,0.3,1);overflow-y:auto;display:flex;flex-direction:column}
.mob-drawer.open{right:0}
.mob-overlay{position:fixed;inset:0;background:rgba(0,0,0,0);z-index:290;pointer-events:none;transition:background .38s}
.mob-overlay.open{background:rgba(0,0,0,0.45);pointer-events:auto}
.mob-drawer-head{padding:18px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #EBEBEB}
.mob-close{width:34px;height:34px;display:flex;align-items:center;justify-content:center;border:1px solid #E0E0E0;border-radius:var(--r);background:none;cursor:pointer;color:#666}
.mob-cta-top{margin:16px;border-radius:var(--r-md);overflow:hidden}
.mob-cta-item{display:flex;align-items:center;gap:12px;padding:16px;text-decoration:none}
.mob-cta-item + .mob-cta-item{border-top:1px solid rgba(255,255,255,0.1)}
.mob-cta-icon{width:36px;height:36px;border-radius:var(--r);display:flex;align-items:center;justify-content:center;flex-shrink:0;background:rgba(255,255,255,0.15)}
.mob-cta-text strong{display:block;font-weight:700;font-size:14px;letter-spacing:-0.01em;color:#fff;line-height:1.2}
.mob-cta-text span{font-weight:400;font-size:12px;color:rgba(255,255,255,0.65);letter-spacing:0.01em}
.mob-nav-list{list-style:none;padding:8px 0}
.mob-nav-list li a{display:flex;align-items:center;gap:12px;padding:14px 20px;font-weight:500;font-size:15px;color:#0A0A0A;text-decoration:none;border-bottom:1px solid #F0F0F0;transition:background .2s}
.mob-nav-list li a:hover{background:#F8F8F8}
.mob-nav-list li a .mob-nav-icon{width:28px;height:28px;border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.mob-drawer-foot{margin-top:auto;padding:20px;border-top:1px solid #EBEBEB}
.mob-drawer-foot a{display:block;text-align:center;font-weight:700;font-size:14px;color:#fff;background:#0A0A0A;padding:14px;border-radius:var(--r);text-decoration:none;margin-bottom:8px}
.mob-drawer-contact{font-weight:400;font-size:13px;color:#999;text-align:center;line-height:1.6}

/* ── REVEAL ── */
.r{opacity:0;transform:translateY(22px);transition:opacity .7s var(--ease),transform .7s var(--ease)}
.r.in{opacity:1;transform:translateY(0)}
.d1{transition-delay:.1s}.d2{transition-delay:.2s}.d3{transition-delay:.3s}.d4{transition-delay:.4s}
.sec-label-w{font-weight:400;font-size:13px;letter-spacing:0.06em;color:var(--silver);margin-bottom:12px}

/* ── FOOTER ── */
footer{background:var(--black);border-top:1px solid rgba(255,255,255,0.05);padding:60px 60px 36px}
.footer-grid{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:56px;margin-bottom:56px}
.footer-logo{display:flex;align-items:center;height:34px;margin-bottom:14px}
.footer-logo svg{height:34px;width:auto}
.footer-tag{font-weight:400;font-size:14px;line-height:1.65;color:var(--silver);max-width:240px}
.fcol-h{font-weight:400;font-size:12px;letter-spacing:0.06em;color:var(--smoke);margin-bottom:16px}
.fcol-links{list-style:none}
.fcol-links li{margin-bottom:8px}
.fcol-links a{font-weight:400;font-size:14px;color:var(--silver);transition:color .2s;text-decoration:none}
.fcol-links a:hover{color:var(--mist)}
.footer-bottom{display:flex;justify-content:space-between;align-items:center;border-top:1px solid rgba(255,255,255,0.05);padding-top:22px}
.footer-copy{font-weight:400;font-size:13px;color:var(--smoke)}
.footer-social{display:flex;gap:16px}
.footer-social a{font-weight:400;font-size:13px;color:var(--smoke);transition:color .2s;text-decoration:none}
.footer-social a:hover{color:var(--mist)}

/* ── RESPONSIVE ── */
@media(max-width:1024px){
  nav{padding:0 24px}
  .nav-links{gap:20px}
  .btn-ghost:not(.keep){display:none}
}
@media(max-width:860px){
  nav{display:none}
  .mob-bar{display:flex}
  body{padding-top:56px}
  footer{padding:40px 20px 28px}
  .footer-grid{grid-template-columns:1fr 1fr;gap:32px}
  .footer-bottom{flex-direction:column;gap:12px;text-align:center}
}

.r{opacity:0;transform:translateY(22px);transition:opacity .7s var(--ease),transform .7s var(--ease)}
.r.in{opacity:1;transform:translateY(0)}
.d1{transition-delay:.1s}.d2{transition-delay:.2s}.d3{transition-delay:.3s}.d4{transition-delay:.4s}

footer{background:var(--black);border-top:1px solid rgba(255,255,255,0.05);padding:60px 60px 36px}
.footer-grid{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:56px;margin-bottom:56px}
.footer-logo{display:flex;align-items:center;height:36px;margin-bottom:16px}
.footer-logo svg{height:36px;width:auto}
.footer-tag{font-weight:400;font-size:14px;line-height:1.65;color:var(--silver);max-width:250px}
.fcol-h{font-weight:400;font-size:12px;letter-spacing:0.06em;color:var(--smoke);margin-bottom:18px}
.fcol-links{list-style:none}
.fcol-links li{margin-bottom:9px}
.fcol-links a{font-weight:400;font-size:14px;color:var(--silver);transition:color .2s}
.fcol-links a:hover{color:var(--mist)}
.footer-bottom{display:flex;justify-content:space-between;align-items:center;border-top:1px solid rgba(255,255,255,0.05);padding-top:24px}
.footer-copy{font-weight:400;font-size:13px;color:var(--smoke)}
.footer-social{display:flex;gap:16px}
.footer-social a{font-weight:700;font-size:12px;letter-spacing:0.01em;color:var(--smoke);transition:color .2s}
.footer-social a:hover{color:var(--mist)}

.btn-wpp{display:inline-flex;align-items:center;gap:8px;font-weight:700;font-size:14px;letter-spacing:0.01em;color:var(--black);background:#25D366;padding:13px 26px;border-radius:var(--r);transition:all .22s;font-family:var(--f)}
.btn-wpp:hover{background:#1fbd5a;transform:translateY(-1px)}
.btn-outline{display:inline-flex;align-items:center;gap:8px;font-weight:400;font-size:14px;letter-spacing:0.02em;color:var(--mist);border:1px solid rgba(255,255,255,0.18);padding:13px 24px;border-radius:var(--r);transition:all .22s;font-family:var(--f)}
.btn-outline:hover{border-color:rgba(255,255,255,0.45);color:var(--pure);transform:translateY(-1px)}
.btn-primary{font-family:var(--f);font-weight:700;font-size:14px;letter-spacing:0.01em;color:var(--black);background:var(--white);padding:14px 36px;border-radius:var(--r);transition:all .22s var(--ease);display:inline-block}
.btn-primary:hover{background:var(--pure);transform:translateY(-1px)}
.btn-link{font-weight:400;font-size:14px;letter-spacing:0.02em;color:var(--silver);border-bottom:1px solid rgba(255,255,255,0.2);padding-bottom:1px;transition:all .2s}
.btn-link:hover{color:var(--pure);border-color:var(--pure)}

@media(max-width:860px){
  nav{padding:0 20px}
  .nav-links{display:none}
  footer{padding:48px 20px 28px}
  .footer-grid{grid-template-columns:1fr 1fr;gap:32px}
  .footer-bottom{flex-direction:column;gap:14px;text-align:center}
}


/* HERO */
.hero{position:relative;min-height:92vh;display:flex;flex-direction:column;justify-content:flex-end;padding:0 60px 96px;overflow:hidden;padding-top:64px}
.hero-video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:0.28}
.hero-grad{position:absolute;inset:0;background:linear-gradient(180deg,rgba(10,10,10,0.2) 0%,rgba(10,10,10,0.05) 30%,rgba(10,10,10,0.6) 68%,rgba(10,10,10,0.9) 100%)}
.hero-body{position:relative;z-index:2;max-width:920px}
.kicker{display:flex;align-items:center;gap:12px;margin-bottom:22px}
.kicker-line{width:32px;height:1px;background:var(--blue);flex-shrink:0}
.kicker-text{font-weight:400;font-size:13px;letter-spacing:0.06em;color:var(--blue)}
.hero-img{width:min(620px,88%);margin-bottom:24px}
.hero-sub{font-weight:400;font-size:clamp(13px,1.3vw,16px);line-height:1.6;color:rgba(184,184,184,0.88);max-width:480px;margin-bottom:48px}
.hero-cta{display:flex;align-items:center;gap:24px}

/* O QUE É */
.oque{background:var(--white);color:#0A0A0A;padding:112px 60px}
.oque-grid{display:grid;grid-template-columns:1fr 1fr;gap:72px;align-items:center}
.sec-label{font-weight:400;font-size:13px;letter-spacing:0.06em;color:#999;margin-bottom:12px}
.sec-h{font-weight:700;font-size:clamp(30px,3.8vw,52px);line-height:0.96;letter-spacing:-0.05em;color:#0A0A0A;margin-bottom:24px}
.sec-h .t{font-weight:400;color:#555;letter-spacing:-0.03em}
.sec-p{font-weight:400;font-size:16px;line-height:1.72;color:#444}
.sec-p + .sec-p{margin-top:14px}
.oque-img{border-radius:var(--r-lg);overflow:hidden}
.oque-img img{width:100%;height:480px;object-fit:cover}

/* PROMESSAS */
.promessas{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:#D8D8D8;margin-top:72px;border-radius:var(--r-md);overflow:hidden}
.prom{background:var(--white);padding:40px 32px}
.prom-bar{display:block;width:20px;height:2px;border-radius:1px;margin-bottom:14px}
.prom-val{font-weight:700;font-size:28px;letter-spacing:-0.04em;color:#0A0A0A;margin-bottom:8px}
.prom-val .t{font-weight:400}
.prom-text{font-weight:400;font-size:14px;line-height:1.65;color:#666}

/* FASES */
.fases{background:var(--charcoal);padding:112px 60px}
.fases-top{display:grid;grid-template-columns:1fr 1fr;gap:64px;align-items:end;margin-bottom:64px}
.fases-sub{font-weight:400;font-size:15px;line-height:1.7;color:var(--silver);padding-top:40px}
.fases-track{display:grid;grid-template-columns:repeat(5,1fr);gap:1px;background:rgba(255,255,255,0.05);border-radius:var(--r-md);overflow:hidden;border:1px solid rgba(255,255,255,0.05)}
.fase{background:var(--charcoal);padding:36px 28px;position:relative;cursor:pointer;transition:background .28s var(--ease)}
.fase:hover{background:var(--mid)}
.fase::after{content:'';position:absolute;top:0;left:0;right:0;height:2px;transform:scaleX(0);transform-origin:left;transition:transform .35s var(--ease)}
.fase:nth-child(1)::after{background:var(--blue)}
.fase:nth-child(2)::after{background:var(--rose)}
.fase:nth-child(3)::after{background:var(--olive)}
.fase:nth-child(4)::after{background:var(--green)}
.fase:nth-child(5)::after{background:var(--purple)}
.fase:hover::after{transform:scaleX(1)}
.fase-n{font-weight:400;font-size:12px;letter-spacing:0.06em;color:var(--smoke);margin-bottom:20px}
.fase-nome{font-weight:700;font-size:17px;letter-spacing:-0.02em;color:var(--pure);margin-bottom:10px;line-height:1.1}
.fase-desc{font-weight:400;font-size:14px;line-height:1.62;color:var(--silver)}

/* 8 FUNDAMENTOS */
.fund{background:var(--black);padding:112px 60px}
.fund-top{display:grid;grid-template-columns:1fr 1fr;gap:64px;align-items:end;margin-bottom:64px}
.fund-intro{font-weight:400;font-size:15px;line-height:1.72;color:var(--silver);padding-top:40px}
.fund-intro strong{color:var(--mist);font-weight:500}
.fund-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:rgba(255,255,255,0.05);border-radius:var(--r-md);overflow:hidden;border:1px solid rgba(255,255,255,0.05)}
.fund-card{background:var(--black);padding:36px 28px;position:relative;transition:background .28s var(--ease)}
.fund-card:hover{background:var(--coal)}
.fund-code{font-weight:700;font-size:44px;line-height:1;letter-spacing:-0.06em;color:rgba(255,255,255,0.18);margin-bottom:14px}
.fund-n{font-weight:400;font-size:12px;letter-spacing:0.06em;color:var(--silver);margin-bottom:6px}
.fund-nome{font-weight:700;font-size:15px;letter-spacing:-0.02em;color:var(--pure);margin-bottom:8px;line-height:1.2}
.fund-desc{font-weight:400;font-size:13px;line-height:1.62;color:var(--silver)}
.fund-bar{position:absolute;bottom:0;left:0;right:0;height:2px;transform:scaleX(0);transform-origin:left;transition:transform .35s var(--ease)}
.fund-card:nth-child(1) .fund-bar{background:var(--blue)}
.fund-card:nth-child(2) .fund-bar{background:var(--rose)}
.fund-card:nth-child(3) .fund-bar{background:var(--olive)}
.fund-card:nth-child(4) .fund-bar{background:var(--green)}
.fund-card:nth-child(5) .fund-bar{background:var(--purple)}
.fund-card:nth-child(6) .fund-bar{background:var(--orange)}
.fund-card:nth-child(7) .fund-bar{background:var(--lime)}
.fund-card:nth-child(8) .fund-bar{background:var(--blue)}
.fund-card:hover .fund-bar{transform:scaleX(1)}

/* ALGORITMO */
.algo{background:var(--blue);padding:96px 60px;display:grid;grid-template-columns:1fr 1fr;gap:72px;align-items:center}
.algo-tag{display:inline-flex;align-items:center;gap:7px;font-weight:400;font-size:12px;letter-spacing:0.06em;color:rgba(255,255,255,0.6);border:1px solid rgba(255,255,255,0.2);padding:5px 14px;border-radius:100px;margin-bottom:24px;width:fit-content}
.algo-h{font-weight:700;font-size:clamp(28px,3.8vw,52px);line-height:0.95;letter-spacing:-0.05em;color:var(--pure);margin-bottom:20px}
.algo-h .t{font-weight:400;opacity:.85}
.algo-p{font-weight:400;font-size:15px;line-height:1.72;color:rgba(255,255,255,0.72);margin-bottom:36px}
.algo-creds{display:flex;gap:28px;flex-wrap:wrap}
.algo-cred{border-left:2px solid rgba(255,255,255,0.2);padding-left:12px}
.algo-cred-v{font-weight:700;font-size:14px;color:var(--pure);margin-bottom:2px}
.algo-cred-l{font-weight:400;font-size:12px;color:rgba(255,255,255,0.55)}
.algo-img{border-radius:var(--r-lg);overflow:hidden}
.algo-img img{width:100%;height:420px;object-fit:cover;object-position:center 15%;filter:brightness(.85) contrast(1.05)}

/* PRODUTOS */
.produtos{background:var(--white);color:#0A0A0A;padding:112px 60px}
.prod-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:2px;background:#DCDCDC;border-radius:var(--r-md);overflow:hidden;margin-top:56px}
.prod-card{background:var(--white);padding:40px 32px;cursor:pointer;transition:background .22s;position:relative}
.prod-card:hover{background:#F5F5F3}
.prod-tag{display:inline-flex;align-items:center;font-weight:500;font-size:12px;letter-spacing:0.04em;color:var(--pure);padding:4px 12px;border-radius:100px;margin-bottom:18px;width:fit-content}
.prod-img{width:100%;height:160px;object-fit:cover;border-radius:var(--r-md);margin-bottom:18px}
.prod-nome{font-weight:700;font-size:19px;letter-spacing:-0.03em;color:#0A0A0A;margin-bottom:8px;line-height:1.1}
.prod-nome .t{font-weight:400}
.prod-desc{font-weight:400;font-size:14px;line-height:1.65;color:#555;margin-bottom:20px}
.prod-link{font-weight:700;font-size:13px;letter-spacing:0.02em;color:#0A0A0A;display:flex;align-items:center;gap:6px}

/* RESULTADOS */
.resultados{background:var(--charcoal);padding:112px 60px;display:grid;grid-template-columns:1fr 1fr;gap:72px;align-items:center}
.res-list{margin-top:44px;display:flex;flex-direction:column;gap:0}
.res-item{display:grid;grid-template-columns:40px 1fr;gap:18px;align-items:start;padding:24px 0;border-bottom:1px solid rgba(255,255,255,0.06)}
.res-item:first-child{border-top:1px solid rgba(255,255,255,0.06)}
.res-icon{width:40px;height:40px;border-radius:var(--r-md);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.res-nome{font-weight:700;font-size:15px;letter-spacing:-0.01em;color:var(--pure);margin-bottom:4px;line-height:1.2}
.res-desc{font-weight:400;font-size:14px;line-height:1.62;color:var(--silver)}
.res-img{border-radius:var(--r-lg);overflow:hidden;position:relative}
.res-img img{width:100%;height:540px;object-fit:cover;filter:brightness(.75) contrast(1.08)}

/* CTA */
.cta{background:var(--black);padding:112px 60px;display:grid;grid-template-columns:1fr 1fr;gap:72px;align-items:center}
.cta-h{font-weight:700;font-size:clamp(30px,4vw,56px);line-height:0.95;letter-spacing:-0.05em;color:var(--pure)}
.cta-h .t{font-weight:400;color:var(--mist)}
.cta-body{font-weight:400;font-size:16px;line-height:1.7;color:var(--silver);margin-bottom:40px}
.cta-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}

@media(max-width:860px){
  .hero{padding:80px 20px 60px;min-height:70vh}
  .oque{padding:72px 20px}
  .oque-grid{grid-template-columns:1fr}
  .promessas{grid-template-columns:1fr}
  .fases{padding:72px 20px}
  .fases-top{grid-template-columns:1fr;gap:24px}
  .fases-track{grid-template-columns:1fr}
  .fund{padding:72px 20px}
  .fund-top{grid-template-columns:1fr;gap:24px}
  .fund-grid{grid-template-columns:1fr 1fr}
  .algo{grid-template-columns:1fr;gap:44px;padding:72px 20px}
  .produtos{padding:72px 20px}
  .prod-grid{grid-template-columns:1fr}
  .resultados{grid-template-columns:1fr;gap:44px;padding:72px 20px}
  .cta{grid-template-columns:1fr;gap:40px;padding:72px 20px}
}

/* Nav item ativo — barrinha abaixo */
.nav-links a.ativo {
  color: var(--pure) !important;
  position: relative;
}
.nav-links a.ativo::after {
  content: '';
  position: absolute;
  bottom: -4px;
  left: 0;
  right: 0;
  height: 2px;
  border-radius: 1px;
  background: var(--blue);
}
/* Cores específicas por seção */
.pg-pesquisas .nav-links a.ativo::after { background: var(--blue); }
.pg-dados     .nav-links a.ativo::after { background: var(--yellow); }
.pg-eventos   .nav-links a.ativo::after { background: var(--yellow); }
.pg-sobre     .nav-links a.ativo::after { background: var(--green); }
.pg-sucessometria .nav-links a.ativo::after { background: var(--blue); }


.pg-dados .nav-links a.ativo{color:var(--pure)!important}
.pg-dados .nav-links a.ativo::after{background:var(--olive)}

/* ── CARDS VERTICAIS 3:4 ── */
.card-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 16px;
}
.card-v {
  background: #FFF;
  border-radius: var(--r-md);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  text-decoration: none;
  transition: transform 0.28s var(--ease), box-shadow 0.28s var(--ease);
  aspect-ratio: 3/4;
}
.card-v:hover { transform: translateY(-3px); box-shadow: 0 12px 40px rgba(0,0,0,0.12); }
.card-v-img {
  width: 100%; aspect-ratio: 1/1;
  object-fit: cover; flex-shrink: 0;
}
.card-v-img-placeholder {
  width: 100%; aspect-ratio: 1/1;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.card-v-body {
  padding: 20px 20px 24px;
  display: flex; flex-direction: column;
  flex: 1;
}
.card-v-tag {
  font-weight:400; font-size: 10px;
  letter-spacing: 0.06em;
  margin-bottom: 8px;
}
.card-v-title {
  font-weight: 700; font-size: 14px;
  letter-spacing: -0.02em; line-height: 1.25;
  color: #0A0A0A; flex: 1;
  margin-bottom: 16px;
}
.card-v-cta {
  font-weight: 700; font-size: 11px;
  letter-spacing: 0.02em;
  display: inline-flex; align-items: center; gap: 5px;
}

/* ── CARROSSEL MOBILE ── */
.carousel-wrap {
  position: relative; overflow: hidden;
}
.carousel-track {
  display: flex; gap: 14px;
  transition: transform 0.45s cubic-bezier(0.16, 1, 0.3, 1);
  will-change: transform;
  cursor: grab;
}
.carousel-track:active { cursor: grabbing; }
.carousel-track .card-v {
  flex-shrink: 0;
  width: calc(80vw - 32px);
  max-width: 280px;
  aspect-ratio: 3/4;
}

/* dark cards (loja) */
.card-v.dark { background: var(--charcoal); }
.card-v.dark .card-v-title { color: var(--pure); }

@media(max-width:860px) {
  .card-grid {
    display: flex;
    overflow: visible;
    gap: 0;
  }
  .card-grid .card-v {
    flex-shrink: 0;
    width: calc(80vw - 32px);
    max-width: 280px;
  }
}


/* ── POPUP MONITOR ── */
.popup-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,0.65);
  z-index: 500; display: none; align-items: center; justify-content: center;
  backdrop-filter: blur(6px);
}
.popup-overlay.open { display: flex; }
.popup-box {
  background: var(--charcoal);
  border-radius: var(--r-lg);
  padding: 48px;
  max-width: 440px; width: 90%;
  border: 1px solid rgba(255,255,255,0.08);
  animation: popIn 0.4s cubic-bezier(0.16,1,0.3,1) both;
  position: relative;
}
@keyframes popIn {
  from { opacity:0; transform:scale(0.93) translateY(16px); }
  to   { opacity:1; transform:scale(1) translateY(0); }
}
.popup-close {
  position: absolute; top: 16px; right: 16px;
  width: 32px; height: 32px;
  background: rgba(255,255,255,0.07); border: none;
  border-radius: var(--r); cursor: pointer; color: var(--silver);
  display: flex; align-items: center; justify-content: center;
  font-family: var(--f); transition: background 0.2s;
}
.popup-close:hover { background: rgba(255,255,255,0.12); }
.popup-label {
  font-weight:400; font-size:12px; letter-spacing:0.08em;
  color: var(--olive); margin-bottom: 12px;
}
.popup-title {
  font-weight:700; font-size:24px; letter-spacing:-0.04em;
  color: var(--pure); line-height:1.1; margin-bottom:8px;
}
.popup-sub {
  font-weight:400; font-size:15px; line-height:1.65;
  color: var(--silver); margin-bottom: 28px;
}
.popup-field {
  width: 100%; background: rgba(255,255,255,0.06);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: var(--r); padding: 13px 16px;
  font-family: var(--f); font-weight:400; font-size:15px;
  color: var(--white); outline: none; transition: border 0.2s;
  margin-bottom: 10px;
}
.popup-field:focus { border-color: var(--olive); }
.popup-field::placeholder { color: var(--smoke); }
.popup-btn {
  width: 100%; font-weight:700; font-size:15px;
  color: #0A0A0A; background: var(--olive);
  padding: 14px; border-radius: var(--r);
  border: none; cursor: pointer; font-family: var(--f);
  transition: background 0.2s; margin-top: 4px;
}
.popup-btn:hover { background: #cabb45; }
.popup-note {
  font-weight:400; font-size:12px; color: var(--smoke);
  text-align: center; margin-top: 14px; line-height: 1.6;
}


/* ── TEXTOS CORRIDOS — largura máxima ── */
.sec-p, p.hero-sub, p.marcus-text, p.bigdata-p, p.algo-p, p.cta-body,
p.sede-p, p.fases-sub, p.fund-intro, p.res-desc, p.dif-p, p.conceito-p {
  max-width: 620px;
}
.sec-p + .sec-p { margin-top: 14px; }
/* Textos dentro de grid 2col — deixar fluir com a coluna */
[style*="grid-template-columns:1fr 1fr"] p,
[style*="grid-template-columns:1fr 1fr"] .sec-p {
  max-width: 100%;
}

/* ── MOBILE RESPONSIVO COMPLETO ── */
@media(max-width:860px){
  /* Imagens wide com altura fixa grande cortavam demais no mobile — reduz proporção */
  .oque-img img, .algo-img img, .res-img img { height:auto !important; aspect-ratio:16/10 !important; }

  /* Nav */
  nav { display: none !important; }
  .mob-bar { display: flex !important; }
  body { padding-top: 56px; }

  /* Grids 2col → 1col */
  .marcus, .sede-body, .bigdata, .equipe > div,
  .algo, .cta, .resultados, .fases-top, .fund-top,
  [style*="grid-template-columns:1fr 1fr"],
  [style*="grid-template-columns:2fr 1fr"],
  [style*="grid-template-columns:1fr 2fr"] {
    grid-template-columns: 1fr !important;
    gap: 32px !important;
  }
  /* Grids 3col → 1col */
  .numeros .num-row,
  [style*="grid-template-columns:repeat(3"] {
    grid-template-columns: 1fr 1fr !important;
  }
  /* Grids 4col → 2col */
  [style*="grid-template-columns:repeat(4"],
  .eco-grid { grid-template-columns: 1fr 1fr !important; }
  /* Grids 8col → 4col */
  [style*="grid-template-columns:repeat(8"] {
    grid-template-columns: repeat(4,1fr) !important;
  }

  /* Sections padding */
  section, .eco, .fund, .algo, .cta, .produtos, .resultados,
  [style*="padding:112px 60px"],
  [style*="padding:96px 60px"],
  [style*="padding:80px 60px"] {
    padding: 64px 20px !important;
  }
  footer { padding: 48px 20px 28px !important; }
  .footer-grid { grid-template-columns: 1fr 1fr !important; gap: 32px !important; }
  .footer-bottom { flex-direction: column !important; gap: 12px !important; text-align: center; }

  /* Clientes galeria */
  .rtabs, .rpanel { padding: 0 20px !important; }
  [style*="padding:0 60px;display:flex"] { padding: 0 20px !important; flex-direction: column !important; }

  /* Marcus foto — não cortar rosto */
  .marcus-pic, .marcus > div:first-child { height: 360px !important; }
  .marcus > div[style*="position:absolute"] img { object-position: right top !important; }

  /* Hero */
  .hero { padding: 0 20px 60px !important; min-height: 520px !important; }
  .hero-h { font-size: clamp(38px,11vw,56px) !important; }
  .hero-body { max-width: 100% !important; }
  .icone-bg-hero { display: none !important; }

  /* Cards carrossel */
  .card-grid {
    display: flex !important;
    overflow-x: auto !important;
    scroll-snap-type: x mandatory;
    -webkit-overflow-scrolling: touch;
    gap: 12px !important;
    padding: 0 20px 20px !important;
    cursor: grab;
    scrollbar-width: none;
  }
  .card-grid::-webkit-scrollbar { display: none; }
  .card-grid .card-v, .card-grid > a, .card-grid > div {
    flex-shrink: 0 !important;
    width: 72vw !important;
    max-width: 260px !important;
    scroll-snap-align: start;
  }

  /* Textos max-width mobile */
  .sec-p, p { max-width: 100% !important; }

  /* Sede */
  .sede { height: 480px !important; }
  .sede-body { grid-template-columns: 1fr !important; padding: 0 20px 40px !important; }

  /* Mapas */
  [style*="margin:0 60px"] { margin: 0 !important; border-radius: 0 !important; }
}

@media(min-width:861px) and (max-width:1180px){
  nav { padding: 0 24px !important; }
  .nav-links { gap: 20px !important; }
  .btn-ghost:not(.keep) { display: none !important; }
  [style*="grid-template-columns:repeat(4"] { grid-template-columns: repeat(2,1fr) !important; }
  .eco-grid { grid-template-columns: repeat(2,1fr) !important; }
  section[style*="padding:112px 60px"] { padding: 80px 40px !important; }
}

</style>
<!-- HERO -->
<section class="hero">
  <video class="hero-video" autoplay muted loop playsinline preload="none">
    <source src="https://www.datastore.com.br/novosite2026/dados.mp4" type="video/mp4">
  </video>
  <div class="hero-grad"></div>
  <div aria-hidden="true" style="position:absolute;inset:0;background:#0A0A0A;opacity:0.10;mix-blend-mode:multiply;z-index:0;pointer-events:none"></div>
  <div aria-hidden="true" style="position:absolute;inset:0;background:#B7A73E;opacity:0.45;mix-blend-mode:color;z-index:0;pointer-events:none"></div>
  <div class="icone-bg-hero" aria-hidden="true" style="position:absolute;inset:0;overflow:hidden;z-index:1;"><svg viewBox="0 0 1080 1080" xmlns="http://www.w3.org/2000/svg" style="position:absolute;right:-8%;top:50%;transform:translateY(-50%);width:70%;height:auto;opacity:0.12;pointer-events:none;z-index:1"><path d="m628.77,136.81h1.17c37.91.78,69.18,30.88,70.74,68.79.78,18.76-5.86,36.74-18.37,50.81-9.77,10.55-14.85,23.84-14.85,37.52v1.95c0,11.33,9.38,21.11,21.11,21.11,75.82.78,152.82,2.35,228.64,5.47h.39c7.04.39,12.51,5.47,13.68,12.12v1.95c3.13,75.43,4.69,152.43,5.47,228.25,0,5.86-2.34,11.33-6.25,15.24-3.91,4.3-9.38,6.25-15.24,6.25-7.82,0-15.63-3.52-21.11-9.38-13.68-15.24-32.83-23.84-53.15-23.84h-2.74c-18.37.78-35.18,8.6-48.07,21.5-12.9,13.29-20.32,30.49-20.32,48.46-.39,19.15,7.04,37.52,20.71,51.2,13.68,13.68,31.66,21.11,50.81,21.11,20.32,0,39.86-8.6,53.15-24.23,5.08-5.86,12.9-9.38,20.71-9.38,5.86,0,11.33,2.35,15.24,6.25,4.3,4.3,6.25,9.38,6.25,15.24-.39,75.82-2.34,152.43-5.08,227.86,0,5.86-4.3,11.33-9.77,13.29l-2.34.78c-1.17.39-2.74.78-3.91.78-75.04,2.74-151.25,4.69-226.29,5.08-5.86,0-11.33-2.35-15.24-6.25-4.3-4.3-6.64-9.38-6.64-15.24,0-7.82,3.52-15.63,9.38-20.71,15.24-13.68,24.23-33.22,23.84-53.54,0-19.15-7.43-37.13-21.11-50.81-13.29-13.29-31.27-20.71-50.03-20.71h-1.17c-18.37.39-35.57,7.43-48.85,20.32-13.29,12.9-20.71,30.09-21.5,48.07-.78,21.5,7.82,41.82,23.84,55.89,5.86,5.47,9.38,12.9,9.38,21.11,0,5.86-2.34,11.33-6.25,15.24-4.3,3.91-9.38,6.25-15.24,6.25-75.43-.78-151.64-2.35-226.68-5.08-1.17,0-2.74-.39-3.91-.78l-2.34-.78c-5.47-1.95-9.77-7.03-9.77-13.29-2.74-75.43-4.69-152.43-5.47-228.25,0-11.73-9.77-21.11-21.11-21.11h-1.95c-13.68,0-26.97,5.08-37.52,14.85-13.29,12.12-30.09,18.76-48.07,18.76-19.54,0-37.52-7.43-51.2-21.5-13.68-13.68-20.71-32.05-20.71-51.59.39-37.91,30.88-69.18,68.79-70.35h2.74c17.59,0,34.78,6.64,47.68,18.37,10.55,9.38,24.23,14.85,37.91,14.85h1.95c11.73,0,21.11-9.38,21.11-21.11.78-75.82,2.34-152.82,5.47-228.64v-.39c.39-7.04,5.47-12.51,12.12-13.68h1.95c75.82-3.13,152.82-4.69,228.64-5.47,11.73,0,21.11-9.77,21.11-21.11v-1.95c0-13.68-5.08-26.97-14.46-37.52-12.12-13.29-18.76-30.49-18.76-48.46,0-19.15,7.43-37.52,21.5-51.2,13.29-10.94,30.88-18.37,50.03-18.37h0m0-85.2h0c-41.43,0-80.51,16.02-110.22,45.34-30.09,29.7-46.9,69.57-46.9,111.78,0,8.6.78,16.81,1.95,25.01-45.73.78-91.46,2.35-136.4,4.3-3.52,0-7.03.39-10.55.78h-.39c-47.68,7.04-83.25,46.9-84.81,94.97-1.56,45.34-3.13,91.06-3.91,136.79-8.21-1.17-16.42-1.95-24.62-1.95h-6.25c-83.25,3.13-149.3,71.13-150.47,154.38-.78,42.6,15.24,82.47,45.34,112.56,29.7,30.09,69.57,46.9,111.78,46.9,8.21,0,16.81-.78,25.01-1.95.78,45.73,2.34,91.06,3.91,136.01,1.56,41.43,28.92,77.78,68.01,90.67l2.34.78c8.99,3.13,18.37,4.69,27.75,5.08,75.82,2.74,152.82,4.69,229.03,5.08h.78c21.5,0,42.21-6.25,59.8-18.37,17.59,11.73,38.3,18.37,59.8,18.37h.78c76.21-.39,153.21-2.35,229.03-5.47,9.38-.39,18.76-1.95,27.75-5.08l2.34-.78c39.08-12.9,66.44-49.25,68.01-90.67,2.74-76.21,4.69-153.99,5.08-230.59,0-21.89-6.25-42.99-18.37-60.58,12.12-17.59,18.37-38.69,18.37-60.58-.78-76.6-2.34-154.38-5.47-230.98,0-3.52-.39-6.64-.78-10.16v-.78c-6.64-47.29-46.12-83.25-93.8-85.59h-1.17c-45.34-1.56-91.06-3.13-136.79-3.91,1.56-10.16,2.34-20.32,1.95-30.88-3.13-83.25-71.13-149.69-154.38-150.86h-1.56q-1.56.39-1.95.39h0Z" fill="#b7a73e"/></svg></div>
  <div class="hero-body">
    <div class="kicker r">
      <span class="kicker-line" style="background:var(--olive)"></span>
      <span class="kicker-text" style="color:var(--olive)">Dados & Inteligência de Mercado</span>
    </div>
    <img src="https://www.datastore.com.br/novosite2026/titulo-dados.png" alt="Dados — datastore" class="hero-img r d1">
    <p class="hero-sub r d2">
      O maior acervo de dados sobre demanda imobiliária da <strong>América Latina</strong>. Mais de R$ 1,5 trilhão em VGV pesquisado — histórico para embasar qualquer decisão.
    </p>
    <div class="hero-cta r d3">
      <a href="#monitor" class="btn-primary">Acessar o Monitor</a>
      <a href="#fale" class="btn-link">Solicitar relatório</a>
    </div>
  </div>
</section>

<!-- O MAIOR ACERVO -->
<section style="background:var(--white);color:#0A0A0A;padding:112px 60px;">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:72px;align-items:center;">
    <div class="r">
      <div class="sec-label">O maior acervo imobiliário</div>
      <h2 class="sec-h" style="margin-bottom:28px;">
        Não basta ter dados.<br>
        <span class="t">É preciso saber<br>o que eles dizem.</span>
      </h2>
      <p class="sec-p" style="margin-bottom:16px;">
        Pesquisando o mercado imobiliário brasileiro de Norte a Sul. Mais de <strong>12.000 projetos realizados</strong>, +3.500 clientes, mais de <strong>R$ 1,5 trilhão</strong> em VGV pesquisado.
      </p>
      <p class="sec-p">
        Uma base que nenhuma outra empresa no Brasil — ou na <strong>América Latina</strong> — conseguiu construir. A Datastore usa esses dados para gerar inteligência real para cada empreendimento, em cada fase.
      </p>
    </div>
    <div class="r d2">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:2px;background:#E0E0E0;border-radius:var(--r-lg);overflow:hidden;">
        <div style="background:var(--white);padding:36px 28px;">
          <div style="font-weight:700;font-size:clamp(32px,4vw,48px);line-height:0.88;letter-spacing:-0.055em;color:#0A0A0A;margin-bottom:8px;"><span style="font-weight:300">+</span>R$ 1,5 trilhão</div>
          <span style="display:block;width:18px;height:2px;border-radius:1px;background:var(--blue);margin-bottom:10px;"></span>
          <div style="font-weight:400;font-size:13px;color:#777;">em VGV pesquisado</div>
        </div>
        <div style="background:#F8F8F6;padding:36px 28px;">
          <div style="font-weight:700;font-size:clamp(32px,4vw,48px);line-height:0.88;letter-spacing:-0.055em;color:#0A0A0A;margin-bottom:8px;">32</div>
          <span style="display:block;width:18px;height:2px;border-radius:1px;background:var(--rose);margin-bottom:10px;"></span>
          <div style="font-weight:400;font-size:13px;color:#777;">anos de dados históricos</div>
        </div>
        <div style="background:#F8F8F6;padding:36px 28px;">
          <div style="font-weight:700;font-size:clamp(32px,4vw,48px);line-height:0.88;letter-spacing:-0.055em;color:#0A0A0A;margin-bottom:8px;"><span style="font-weight:300">+</span>12k</div>
          <span style="display:block;width:18px;height:2px;border-radius:1px;background:var(--olive);margin-bottom:10px;"></span>
          <div style="font-weight:400;font-size:13px;color:#777;">projetos no acervo</div>
        </div>
        <div style="background:var(--white);padding:36px 28px;">
          <div style="font-weight:700;font-size:clamp(32px,4vw,48px);line-height:0.88;letter-spacing:-0.055em;color:#0A0A0A;margin-bottom:8px;">85%</div>
          <span style="display:block;width:18px;height:2px;border-radius:1px;background:var(--green);margin-bottom:10px;"></span>
          <div style="font-weight:400;font-size:13px;color:#777;">do território brasileiro coberto</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- MONITOR -->
<section style="background:var(--olive);padding:96px 60px;display:grid;grid-template-columns:1fr 1fr;gap:80px;align-items:center;overflow:hidden;" id="monitor">
  <div class="r">
    <div style="font-weight:400;font-size:13px;letter-spacing:0.06em;color:rgba(0,0,0,.5);margin-bottom:12px;">Datastore Monitor — gratuito</div>
    <h2 style="font-weight:700;font-size:clamp(34px,4.5vw,60px);line-height:0.92;letter-spacing:-0.05em;color:#0A0A0A;margin-bottom:24px;">
      O termômetro trimestral<br>
      <span style="font-weight:300">do mercado.</span>
    </h2>
    <p style="font-weight:400;font-size:16px;line-height:1.75;color:rgba(0,0,0,.65);">
      A cada trimestre, a Datastore publica gratuitamente o <strong style="color:#0A0A0A;font-weight:700;">Monitor da Demanda Imobiliária Residencial</strong> — o maior levantamento sobre intenção de compra de imóveis no Brasil. Baixe qualquer edição, é grátis.
    </p>
  </div>
  <div class="r d2" id="dts-monitor-carrossel" style="display:flex;gap:20px;overflow-x:auto;scroll-behavior:smooth;scrollbar-width:none;-ms-overflow-style:none;padding-bottom:8px;">
    <style>#dts-monitor-carrossel::-webkit-scrollbar{display:none;}</style>
    <?php
    if ( class_exists( 'WooCommerce' ) ) :
      $dts_monitores = wc_get_products( array(
        'limit'    => -1,
        'status'   => 'publish',
        's'        => 'Monitor',
        'orderby'  => 'date',
        'order'    => 'DESC',
      ) );
      foreach ( $dts_monitores as $dts_mon ) :
        $dts_mon_img = $dts_mon->get_image_id() ? wp_get_attachment_image_url( $dts_mon->get_image_id(), 'medium' ) : wc_placeholder_img_src( 'medium' );
    ?>
    <a href="<?php echo esc_url( $dts_mon->get_permalink() ); ?>" style="flex:0 0 150px;text-decoration:none;text-align:center;">
      <div style="width:150px;height:150px;border-radius:50%;overflow:hidden;margin:0 auto 14px;background:rgba(0,0,0,0.1);">
        <img src="<?php echo esc_url( $dts_mon_img ); ?>" alt="<?php echo esc_attr( $dts_mon->get_name() ); ?>" style="width:100%;height:100%;object-fit:cover;">
      </div>
      <div style="font-weight:700;font-size:14px;color:#0A0A0A;line-height:1.3;"><?php echo esc_html( $dts_mon->get_name() ); ?></div>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<!-- DIFERENCIAIS -->
<section style="background:var(--charcoal);padding:112px 60px;">
  <div style="max-width:800px;margin:0 auto;text-align:center;" class="r">
    <div class="sec-label-w" style="text-align:center;margin-bottom:16px;">O diferencial Datastore</div>
    <h2 class="sec-h-w" style="margin-bottom:28px;text-align:center;">
      A diferença entre ter dados<br>
      <span class="t">e saber usá-los.</span>
    </h2>
    <p class="sec-p-w" style="text-align:center;">
      Qualquer empresa pode comprar dados. A Datastore transforma décadas de pesquisa em leitura de mercado precisa — entregando ao empreendedor não apenas números, mas <strong style="color:var(--mist)">a interpretação correta</strong> para cada decisão imobiliária.
    </p>
  </div>
</section>

<!-- CTA -->
<section style="background:var(--black);padding:112px 60px;display:grid;grid-template-columns:1fr 1fr;gap:72px;align-items:center;" id="fale">
  <div class="r">
    <h2 style="font-weight:700;font-size:clamp(30px,4vw,54px);line-height:0.95;letter-spacing:-0.05em;color:var(--pure);">
      Precisa de dados<br>específicos do seu<br>
      <span style="font-weight:300;color:var(--mist)">mercado?</span>
    </h2>
  </div>
  <div class="r d2">
    <p style="font-weight:400;font-size:16px;line-height:1.7;color:var(--silver);margin-bottom:36px;">Fale com um executivo Datastore. Identificamos os dados mais relevantes para o seu empreendimento e entregamos com a leitura certa.</p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <a href="https://wa.me/5519983830418" class="btn-wpp"><svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a5.48 5.48 0 00-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479s1.065 2.875 1.213 3.074c.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/></svg>WhatsApp</a>
      <a href="tel:+551935157399" class="btn-outline">(19) 3515-7399</a>
    </div>
  </div>
</section>



<!-- POPUP MONITOR -->
<div class="popup-overlay" id="monitorPopup">
  <div class="popup-box">
    <button class="popup-close" onclick="document.getElementById('monitorPopup').classList.remove('open')" aria-label="Fechar">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
    <div class="popup-label">Datastore Monitor — gratuito</div>
    <div class="popup-title">Receba o Monitor<br>agora mesmo.</div>
    <p class="popup-sub">Preencha os dados abaixo e enviaremos o link de acesso ao <strong>Datastore Monitor</strong> diretamente no seu e-mail.</p>
    <input class="popup-field" type="text" placeholder="Seu nome completo">
    <input class="popup-field" type="tel" placeholder="WhatsApp (com DDD)">
    <input class="popup-field" type="email" placeholder="Seu melhor e-mail">
    <button class="popup-btn">Quero receber o Monitor →</button>
    <p class="popup-note">Seus dados não serão compartilhados.</p>
  </div>
</div>

<!-- video_dados_marker -->

<?php dts_blog_roll( 'artigos', -1, 'Artigos sobre Dados' ); ?>


<style id="dts-mobile-final">
  .dts-per{display:block;font-weight:400;font-size:12px;line-height:1.45;margin-top:4px;opacity:.72;letter-spacing:0}
  .dts-per-i{font-weight:400;font-size:0.78em;line-height:1.4;opacity:.72;letter-spacing:0}
@media(max-width:860px){
  /* ---- 1. TEXTO DE CORPO: peso e contraste (principal queixa) ---- */
  p, li,
  .sec-p, .hero-sub, .cta-body, .cta-p, .algo-p, .mvv-p,
  .fase-desc, .fases-sub, .fund-desc, .res-desc, .prod-desc,
  .prom-text, .mnum-desc, .manifesto-p, .marcus-bio, .marcus-text,
  .bigdata-p, .tl-text, .oque-text, .eco-text, .equipe-p, .sede-p,
  .card-v-text, .dif-text, .mc-p, .algo-desc, .num-body, .num-desc {
    font-weight: 500 !important;
  }
  /* Fundo claro: cinzas apagados ficam escuros */
  .sec-p, .manifesto-p, .prod-desc, .prom-text, .mnum-desc,
  .tl-text, .oque-text, .equipe-p { color: #333 !important; }
  [style*="color:#888"], [style*="color:#999"], [style*="color:#777"],
  [style*="color:#666"], [style*="color:#555"], [style*="color:#444"],
  [style*="color: #888"], [style*="color: #999"], [style*="color: #666"] {
    color: #3a3a3a !important;
  }
  /* Fundo escuro: cinzas apagados ficam claros */
  .cta-body, .cta-p, .algo-p, .mvv-p, .fase-desc, .fases-sub,
  .fund-desc, .res-desc, .marcus-bio, .hero-sub, .bigdata-p {
    color: #DCDCDC !important;
  }
  [style*="color:var(--silver)"], [style*="color:var(--mist)"],
  [style*="color:rgba(255,255,255,0.6"], [style*="color:rgba(255,255,255,0.7"],
  [style*="color:rgba(255,255,255,.6"], [style*="color:rgba(255,255,255,.7"] {
    color: #DCDCDC !important;
  }
  /* ---- 2. TITULOS: segunda linha nao fica fina ---- */
  h1 span, h2 span, h3 span, h1 .t, h2 .t, h3 .t,
  .sec-h .t, .sec-h-w .t, .algo-h .t, .algo-title .t, .cta-h .t,
  .prod-nome .t, .prom-val .t, .sede-h .t, .mnum-val .t,
  [style*="font-weight:300"], [style*="font-weight: 300"] {
    font-weight: 400 !important;
  }
  /* ---- 3. ESPACO LATERAL SOBRANDO ---- */
  [style*="padding:112px 60px"], [style*="padding:96px 60px"],
  [style*="padding:80px 60px"],  [style*="padding:72px 60px"],
  [style*="padding:60px 60px"],  [style*="padding:0 60px"],
  [style*="padding: 112px 60px"],[style*="padding: 96px 60px"] {
    padding-left: 20px !important; padding-right: 20px !important;
  }
  [style*="margin:0 60px"], [style*="margin: 0 60px"] {
    margin-left: 20px !important; margin-right: 20px !important;
  }
  .oque, .fases, .fund, .algo, .cta, .produtos, .resultados,
  .eco, .midia, .timeline, .equipe, .numeros, .bigdata, .sede, .sec,
  .manifesto, .mvv, .conceito, .dif, .cases {
    padding-left: 20px !important; padding-right: 20px !important;
  }
  .dts-team-wrap { width: 100% !important; margin: 0 !important; }
}

/* ═══ TIPOGRAFIA v20 — corpo em fonte de sistema (à prova de erro) ═══
   CORPO DE TEXTO (tudo que herda de --f): pilha de sistema nativa — cada
   máquina usa a própria fonte (SF no Mac/iPhone, Segoe no Windows, Roboto
   no Android). Zero download, zero cache, zero clone pirata: não tem como falhar.
   TÍTULOS (h1-h6, incluindo os spans claros dentro deles): mantêm a
   Helvetica Neue da identidade — 'HN' embutida; no Safari, a de sistema Apple. */
:root{--f:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;--fhead:'HN','Helvetica Neue',Helvetica,Arial,sans-serif}
@supports (font:-apple-system-body){:root{--fhead:'Helvetica Neue',Helvetica,Arial,sans-serif}}
body{font-family:var(--f)}
h1,h2,h3,h4,h5,h6{font-family:var(--fhead)}
</style>

<?php get_footer(); ?>
