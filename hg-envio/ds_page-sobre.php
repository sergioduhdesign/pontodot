<?php
/**
 * Template Name: Sobre Datastore 2026
 */
$dts_slug = get_post_field( 'post_name', get_the_ID() );
?>
<?php $GLOBALS['dts_body_class'] = 'pg-sobre'; get_header(); ?>
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
  --smoke:#363636; --silver:#888; --mist:#B8B8B8; --white:#EFEFED; --pure:#FFF;
  --blue:#335DA8; --olive:#B7A73E; --rose:#CE3F5A; --purple:#8C539B;
  --lime:#CBCB3A; --green:#5FB352; --orange:#D2633C;
  --f:'Helvetica Neue','HN',Helvetica,Arial,sans-serif;
  --ease:cubic-bezier(0.16,1,0.3,1);
  --r:8px; --r-md:14px; --r-lg:20px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body.pg-sobre{background:var(--black);color:var(--white);font-family:var(--f);font-weight:400;-webkit-font-smoothing:antialiased;overflow-x:hidden}
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
.nav-links .nav-link.ativo::after{content:'';position:absolute;bottom:-4px;left:0;right:0;height:2px;border-radius:1px;background:var(--blue)}
.nav-right{display:flex;align-items:center;gap:6px}
.btn-succ{display:inline-flex;align-items:center;gap:6px;font-weight:700;font-size:13px;letter-spacing:0.01em;color:#fff;background:#335DA8;padding:8px 14px;border-radius:var(--r);text-decoration:none;transition:all .2s}
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

.sec-label{font-weight:400;font-size:13px;letter-spacing:0.06em;color:#999;margin-bottom:12px}
.sec-label-w{font-weight:400;font-size:13px;letter-spacing:0.06em;color:var(--silver);margin-bottom:12px}
.sec-h{font-weight:700;font-size:clamp(30px,3.8vw,52px);line-height:0.96;letter-spacing:-0.05em;color:#0A0A0A}
.sec-h .t{font-weight:400;color:#555;letter-spacing:-0.03em}
.sec-h-w{font-weight:700;font-size:clamp(30px,3.8vw,52px);line-height:0.96;letter-spacing:-0.05em;color:var(--pure)}
.sec-h-w .t{font-weight:400;color:var(--mist);letter-spacing:-0.03em}
.sec-p{font-weight:400;font-size:16px;line-height:1.72;color:#444}
.sec-p-w{font-weight:400;font-size:16px;line-height:1.72;color:var(--silver)}

.btn-wpp{display:inline-flex;align-items:center;gap:8px;font-weight:700;font-size:14px;color:var(--black);background:#25D366;padding:13px 26px;border-radius:var(--r);transition:all .22s;font-family:var(--f)}
.btn-wpp:hover{background:#1fbd5a;transform:translateY(-1px)}
.btn-outline{display:inline-flex;align-items:center;gap:8px;font-weight:400;font-size:14px;color:var(--mist);border:1px solid rgba(255,255,255,0.18);padding:13px 24px;border-radius:var(--r);transition:all .22s;font-family:var(--f)}
.btn-outline:hover{border-color:rgba(255,255,255,0.45);color:var(--pure);transform:translateY(-1px)}
.btn-primary{font-family:var(--f);font-weight:700;font-size:14px;color:var(--black);background:var(--white);padding:14px 36px;border-radius:var(--r);transition:all .22s var(--ease);display:inline-block}
.btn-primary:hover{background:var(--pure);transform:translateY(-1px)}
.btn-link{font-weight:400;font-size:14px;color:var(--silver);border-bottom:1px solid rgba(255,255,255,0.2);padding-bottom:1px;transition:all .2s}
.btn-link:hover{color:var(--pure);border-color:var(--pure)}

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
.footer-social a{font-weight:700;font-size:12px;color:var(--smoke);transition:color .2s}
.footer-social a:hover{color:var(--mist)}


/* HERO SPLIT */
.hero{display:grid;grid-template-columns:1fr 1fr;min-height:100vh;padding-top:64px;background:var(--black);overflow:hidden}
.hero-left{display:flex;flex-direction:column;justify-content:flex-end;padding:80px 64px 96px;position:relative}
.hero-ano{position:absolute;top:80px;left:40px;font-weight:700;font-size:clamp(120px,16vw,220px);line-height:1;letter-spacing:-0.08em;color:transparent;-webkit-text-stroke:1px rgba(255,255,255,0.05);pointer-events:none;user-select:none}
.kicker{display:flex;align-items:center;gap:12px;margin-bottom:22px;position:relative;z-index:2}
.kicker-line{width:32px;height:1px;background:var(--blue);flex-shrink:0}
.kicker-text{font-weight:400;font-size:13px;letter-spacing:0.06em;color:var(--blue)}
.hero-h{font-weight:700;font-size:clamp(44px,5.5vw,80px);line-height:0.89;letter-spacing:-0.055em;color:var(--pure);margin-bottom:28px;position:relative;z-index:2}
.hero-h .t{font-weight:300;color:var(--mist);letter-spacing:-0.03em}
.hero-sub{font-weight:400;font-size:clamp(13px,1.3vw,16px);line-height:1.6;color:rgba(184,184,184,0.88);max-width:440px;position:relative;z-index:2}
.hero-right{position:relative;overflow:hidden;background:var(--coal)}
.hero-right img{width:100%;height:100%;object-fit:cover;object-position:top center;filter:brightness(.88) contrast(1.06);transition:transform .8s var(--ease)}
.hero-right:hover img{transform:scale(1.03)}
.hero-right::after{content:'';position:absolute;inset:0;background:none}
.hero-caption{position:absolute;bottom:36px;right:36px;z-index:3;text-align:right}
.hero-caption-name{font-weight:700;font-size:15px;color:var(--pure)}
.hero-caption-role{font-weight:400;font-size:13px;color:var(--silver)}

/* MANIFESTO */
.manifesto{background:var(--white);color:#0A0A0A;padding:112px 60px}
.manifesto-grid{display:grid;grid-template-columns:1fr 1fr;gap:72px;align-items:start}
.manifesto-p{font-weight:400;font-size:16px;line-height:1.75;color:#444}
.manifesto-p + .manifesto-p{margin-top:16px}
.manifesto-p strong{font-weight:700;color:#0A0A0A}
.nums-grid{display:grid;grid-template-columns:1fr 1fr;gap:2px;background:#D8D8D8;border-radius:var(--r-lg);overflow:hidden;margin-top:8px}
.mnum{background:var(--white);padding:36px 28px}
.mnum-val{font-weight:700;font-size:clamp(32px,3.5vw,48px);line-height:0.88;letter-spacing:-0.055em;color:#0A0A0A;margin-bottom:8px}
.mnum-val .t{font-weight:400}
.mnum-bar{display:block;width:18px;height:2px;border-radius:1px;margin-bottom:10px}
.mnum-desc{font-weight:400;font-size:13px;line-height:1.5;color:#777}

/* MARCUS PROFILE */
.marcus{display:grid;grid-template-columns:5fr 7fr;min-height:640px;background:var(--black)}
.marcus-pic{position:relative;overflow:hidden;background:var(--coal)}
.marcus-pic img{width:100%;height:100%;object-fit:cover;object-position:top center;filter:brightness(.88) contrast(1.06)}

.marcus-body{padding:72px 64px 72px 52px;display:flex;flex-direction:column;justify-content:center}
.marcus-badge{display:inline-flex;align-items:center;gap:7px;font-weight:400;font-size:12px;letter-spacing:0.06em;color:var(--green);border:1px solid rgba(95,179,82,0.25);padding:5px 14px;border-radius:100px;margin-bottom:24px;width:fit-content}
.marcus-nome{font-weight:700;font-size:clamp(46px,5.5vw,78px);line-height:0.89;letter-spacing:-0.055em;color:var(--pure);margin-bottom:8px}
.marcus-role{font-weight:400;font-size:14px;letter-spacing:0.02em;color:var(--silver);margin-bottom:32px}
.marcus-bio{font-weight:400;font-size:16px;line-height:1.72;color:var(--mist);max-width:440px;margin-bottom:40px}
.marcus-bio p+p{margin-top:14px}
.marcus-bio strong{font-weight:700;color:var(--pure)}
.marcus-creds{display:grid;grid-template-columns:1fr 1fr;gap:14px;max-width:400px}
.cred{border-left:2px solid var(--smoke);padding-left:12px}
.cred-v{font-weight:700;font-size:14px;color:var(--mist);margin-bottom:2px}
.cred-l{font-weight:400;font-size:12px;color:var(--silver);line-height:1.4}

/* MÍDIA */
.midia{background:var(--charcoal);padding:80px 60px}
.midia-logos{display:flex;flex-wrap:wrap;align-items:center;border-top:1px solid rgba(255,255,255,0.06);padding-top:36px;margin-top:36px}
.midia-logo{flex:1;min-width:110px;padding:18px 24px;display:flex;align-items:center;justify-content:center;border-right:1px solid rgba(255,255,255,0.05)}
.midia-logo:last-child{border-right:none}
.midia-logo img{height:22px;width:auto;max-width:88px;object-fit:contain;filter:brightness(0) invert(1);opacity:0.3;transition:opacity .2s}
.midia-logo:hover img{opacity:.8}

/* TIMELINE */
.timeline{background:var(--coal);padding:112px 60px}
.tl-header{display:grid;grid-template-columns:1fr 1fr;gap:64px;align-items:end;margin-bottom:80px}
.tl-sub{font-weight:400;font-size:15px;line-height:1.7;color:var(--silver);padding-top:40px}
.tl-track{position:relative}
.tl-line{position:absolute;left:72px;top:0;bottom:0;width:1px;background:rgba(255,255,255,0.06)}
.tl-item{display:grid;grid-template-columns:72px 1fr;gap:40px;padding-bottom:52px;position:relative}
.tl-item:last-child{padding-bottom:0}
.tl-year-col{display:flex;flex-direction:column;align-items:flex-end;padding-top:2px;position:relative}
.tl-year{font-weight:700;font-size:14px;letter-spacing:-0.01em;color:var(--silver);line-height:1}
.tl-dot{position:absolute;right:-4px;top:5px;width:8px;height:8px;border-radius:50%;background:var(--smoke);border:1px solid rgba(255,255,255,0.08);transition:background .3s,transform .3s}
.tl-item:hover .tl-dot{background:var(--blue);transform:scale(1.6)}
.tl-content{}
.tl-title{font-weight:700;font-size:18px;letter-spacing:-0.02em;color:var(--pure);margin-bottom:8px;line-height:1.15}
.tl-text{font-weight:400;font-size:15px;line-height:1.7;color:var(--silver)}
.tl-tag{display:inline-block;margin-top:10px;font-weight:400;font-size:12px;letter-spacing:0.06em;color:var(--blue);border-bottom:1px solid rgba(51,93,168,0.3);padding-bottom:1px}

/* VIDEO */
.vid{position:relative;height:52vh;min-height:360px;overflow:hidden;display:flex;align-items:center;justify-content:center;background:var(--coal)}
.vid video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.vid-overlay{position:absolute;inset:0;background:rgba(10,10,10,0.52)}
.vid-body{position:relative;z-index:2;text-align:center;padding:0 48px}
.vid-label{font-weight:400;font-size:12px;letter-spacing:0.08em;color:var(--silver);margin-bottom:16px}
.vid-h{font-weight:700;font-size:clamp(26px,4vw,54px);line-height:0.93;letter-spacing:-0.05em;color:var(--pure);margin-bottom:24px}
.vid-h .t{font-weight:300;color:var(--mist)}
.vid-btn{display:inline-flex;align-items:center;gap:10px;font-weight:400;font-size:13px;letter-spacing:0.04em;color:var(--pure);border:1px solid rgba(255,255,255,0.2);padding:12px 28px;border-radius:var(--r);cursor:pointer;background:transparent;font-family:var(--f);transition:all .22s}
@media(max-width:860px){
  .vid { height:auto !important; min-height:auto !important; aspect-ratio:16/9; display:block !important; }
  .vid-overlay { display:none !important; }
  .vid-body { display:none !important; }
  .vid-mobile-caption {
    display:block !important; background:#FFFFFF; padding:32px 20px; text-align:left;
  }
  .vid-mobile-caption .vid-label { color:#888 !important; }
  .vid-mobile-caption .vid-h { color:#0A0A0A !important; }
  .vid-mobile-caption .vid-h .t { color:#666 !important; }
  .vid-mobile-caption .vid-btn { color:#0A0A0A !important; border-color:rgba(10,10,10,0.2) !important; }
}
.vid-mobile-caption { display:none; }
.vid-btn:hover{background:rgba(255,255,255,0.07);border-color:rgba(255,255,255,0.45)}
.vid-play{width:16px;height:16px;border-radius:50%;background:var(--pure);display:flex;align-items:center;justify-content:center}

/* EQUIPE */
.equipe{background:var(--white);color:#0A0A0A;padding:112px 60px}
.equipe-foto{width:100%;border-radius:var(--r-lg);overflow:hidden;margin-bottom:48px}
.equipe-foto img{width:100%;height:440px;object-fit:cover;object-position:center top}
.dir-label{font-weight:400;font-size:13px;letter-spacing:0.06em;color:#999;margin-bottom:24px}
.dir-img{width:100%;margin-bottom:20px}
.dir-names{display:grid;grid-template-columns:repeat(6,1fr);border-top:1px solid #E4E4E4;padding-top:18px}
.dir-name{font-weight:700;font-size:13px;letter-spacing:-0.01em;color:#0A0A0A;margin-bottom:2px;line-height:1.4}
.dir-role{font-weight:400;font-size:12px;color:#888;line-height:1.4}
.dir-badge{display:inline-block;margin-top:4px;font-weight:500;font-size:9px;letter-spacing:0.04em;color:var(--pure);padding:2px 8px;border-radius:100px}

/* SEDE */
.sede{position:relative;height:62vh;min-height:440px;overflow:hidden;display:flex;align-items:flex-end}
.sede img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:center 35%;filter:brightness(.5) contrast(1.1)}
.sede-overlay{position:absolute;inset:0;background:linear-gradient(180deg,transparent 20%,rgba(10,10,10,.65) 62%,#0A0A0A 100%)}
.sede-body{position:relative;z-index:2;width:100%;padding:0 60px 64px;display:grid;grid-template-columns:1fr 1fr;gap:72px;align-items:end}
.sede-h{font-weight:700;font-size:clamp(26px,3.5vw,46px);line-height:0.96;letter-spacing:-0.045em;color:var(--pure)}
.sede-h .t{font-weight:400}
.sede-p{font-weight:400;font-size:15px;line-height:1.7;color:var(--mist)}

/* MISSAO/VISAO */
.mvv{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:var(--smoke)}
.mvv-block{background:var(--charcoal);padding:56px 44px}
.mvv-label{font-weight:400;font-size:12px;letter-spacing:0.08em;color:var(--silver);margin-bottom:14px}
.mvv-h{font-weight:700;font-size:19px;letter-spacing:-0.025em;color:var(--pure);margin-bottom:14px;line-height:1.15}
.mvv-p{font-weight:400;font-size:15px;line-height:1.7;color:var(--silver)}

/* CTA */
.cta{background:var(--black);padding:112px 60px;display:grid;grid-template-columns:1fr 1fr;gap:72px;align-items:center}
.cta-h{font-weight:700;font-size:clamp(30px,4vw,54px);line-height:0.95;letter-spacing:-0.05em;color:var(--pure)}
.cta-h .t{font-weight:400;color:var(--mist)}
.cta-p{font-weight:400;font-size:16px;line-height:1.7;color:var(--silver);margin-bottom:36px}
.cta-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}

@media(max-width:860px){
  .hero{grid-template-columns:1fr;min-height:auto}
  .hero-left{padding:80px 20px 60px}
  .hero-right{height:420px}
  .hero-right::after{background:linear-gradient(180deg,transparent 40%,var(--black) 100%)}
  .equipe-foto img{height:auto !important;aspect-ratio:16/10 !important;}
  .manifesto{padding:72px 20px}
  .manifesto-grid{grid-template-columns:1fr;gap:44px}
  .nums-grid{grid-template-columns:1fr 1fr}
  .marcus{grid-template-columns:1fr}
  .marcus-pic{height:400px}
  .marcus-pic::after{background:linear-gradient(180deg,transparent 40%,var(--black) 100%)}
  .marcus-body{padding:44px 20px 60px}
  .marcus-creds{grid-template-columns:1fr 1fr}
  .midia{padding:60px 20px}
  .timeline{padding:72px 20px}
  .tl-header{grid-template-columns:1fr;gap:24px}
  .equipe{padding:72px 20px}
  .dir-names{grid-template-columns:repeat(3,1fr);gap:12px}
  .sede-body{grid-template-columns:1fr;gap:20px;padding:0 20px 48px}
  .mvv{grid-template-columns:1fr}
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
<section style="position:relative;min-height:92vh;display:flex;flex-direction:column;justify-content:flex-end;padding:0 60px 96px;overflow:hidden;padding-top:64px;">
  <!-- Foto equipe de fora a fora, sem corte -->
  <img src="https://www.datastore.com.br/novosite2026/equipe-2023-foto.jpg" alt="Equipe Datastore"
    style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:center top;">
  <!-- Overlay leve apenas na base para leitura -->
  <div style="position:absolute;inset:0;background:linear-gradient(180deg,rgba(10,10,10,0.1) 0%,rgba(10,10,10,0.08) 40%,rgba(10,10,10,0.72) 72%,rgba(10,10,10,0.92) 100%);"></div>
  <!-- Conteúdo -->
  <div style="position:relative;z-index:2;max-width:820px;">
    <div class="kicker r">
      <span class="kicker-line"></span>
      <span class="kicker-text">Desde 1994 · Campinas, Brasil</span>
    </div>
    <h1 class="hero-h r d1">
      Antecipando<br>
      <span class="t">o mercado<br>imobiliário.</span>
    </h1>
    <p class="hero-sub r d2">
      A Datastore nasceu de uma convicção: decisões imobiliárias não podem ser baseadas em achismo. Desde 1994, transformamos estatística em segurança para empreendedores em todo o Brasil.
    </p>
  </div>
</section>

<!-- MANIFESTO -->
<section class="manifesto">
  <div class="manifesto-grid">
    <div class="r">
      <div class="sec-label">Nossa história</div>
      <h2 class="sec-h" style="margin-bottom:28px">
        Uma empresa construída<br>
        <span class="t">sobre dados reais.</span>
      </h2>
      <p class="manifesto-p">Em 1994, Marcus Araujo saiu da Unicamp com uma ideia simples e poderosa: <strong>o mercado imobiliário brasileiro precisava de pesquisa de verdade.</strong> Não de projeções genéricas. Não de feeling de corretor. Precisava de estatística aplicada ao comportamento real do comprador.</p>
      <p class="manifesto-p">Naquele ano nasceu a Datastore — a primeira empresa especializada em <strong>pesquisa de demanda</strong> imobiliária do Brasil. Desde então, desenvolvemos uma metodologia própria, construímos o maior banco de dados imobiliário da <strong>América Latina</strong> e acompanhamos mais de <strong>12.000 lançamentos</strong> de Norte a Sul do país.</p>
      <p class="manifesto-p">Hoje, com clientes em quase <strong>85% do território</strong> nacional, a Datastore é reconhecida como <strong>líder e pioneira do mercado imobiliário</strong> — a empresa que transforma dados em decisões.</p>
    </div>
    <div class="r d2">
      <div class="nums-grid">
        <div class="mnum">
          <div class="mnum-val"><span class="t">+</span>R$ 1,5 trilhão</div>
          <span class="mnum-bar" style="background:var(--blue)"></span>
          <div class="mnum-desc">em VGV pesquisado</div>
        </div>
        <div class="mnum">
          <div class="mnum-val">32</div>
          <span class="mnum-bar" style="background:var(--rose)"></span>
          <div class="mnum-desc">anos de atuação ininterrupta</div>
        </div>
        <div class="mnum">
          <div class="mnum-val"><span class="t">+</span>12.000</div>
          <span class="mnum-bar" style="background:var(--olive)"></span>
          <div class="mnum-desc">projetos realizados em todo o Brasil</div>
        </div>
        <div class="mnum">
          <div class="mnum-val"><span class="t">+</span>3.500</div>
          <span class="mnum-bar" style="background:var(--green)"></span>
          <div class="mnum-desc">clientes entre incorporadores e loteadores</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- MARCUS PROFILE -->
<section class="dts-marcus-profile" style="position:relative;min-height:680px;overflow:hidden;display:grid;grid-template-columns:1fr 1fr;background:var(--black);">
  <div style="position:absolute;inset:0;">
    <img src="https://www.datastore.com.br/novosite2026/foto-marcus-wide.jpg" alt="Marcus Araujo"
      style="width:100%;height:100%;object-fit:cover;object-position:right center;">
  </div>
  <div class="dts-marcus-profile-content" style="position:relative;z-index:2;padding:72px 64px;display:flex;flex-direction:column;justify-content:center;background:linear-gradient(90deg,rgba(10,10,10,0.95) 0%,rgba(10,10,10,0.82) 55%,transparent 100%);">
    <div class="marcus-badge r">✦ Estatístico · CONRE-SP 7691</div>
    <h2 class="marcus-nome r d1">Marcus<br>Araujo</h2>
    <div class="marcus-role r d2">Fundador · Estatístico-chefe · Datastore</div>
    <div class="marcus-bio r d2">
      <p>Bacharel em Estatística, Marcus fundou a Datastore em 1994 com uma missão clara: dar ao mercado imobiliário brasileiro a precisão que ele não tinha.</p>
      <p>Ao longo de três décadas, desenvolveu o <strong>Algoritmo Autoral Datastore</strong> — metodologia exclusiva que mede 8 fundamentos estatísticos de sucesso antes do lançamento.</p>
      <p>Mais de <strong>R$ 1,5 trilhão em VGV pesquisado</strong>. Mais de 12.000 lançamentos acompanhados.</p>
    </div>
    <div class="marcus-creds r d3">
      <div class="cred"><div class="cred-v">1994</div><div class="cred-l">Fundação da Datastore</div></div>
      <div class="cred"><div class="cred-v">Unicamp</div><div class="cred-l">Graduação em Estatística</div></div>
      <div class="cred"><div class="cred-v">CONRE-SP 7691</div><div class="cred-l">Conselho Regional</div></div>
      <div class="cred"><div class="cred-v">+R$ 1,5 trilhão</div><div class="cred-l">em VGV pesquisado</div></div>
    </div>
  </div>
  <div style="position:relative;z-index:1;"></div>
</section>
<style>
@media(max-width:860px){
  .dts-marcus-profile{grid-template-columns:1fr !important;min-height:auto !important;}
  .dts-marcus-profile > div:first-child{position:relative !important;inset:auto !important;height:280px !important;}
  .dts-marcus-profile-content{position:relative !important;padding:32px 20px !important;background:var(--black) !important;}
}
</style>

<!-- MÍDIA -->
<section class="midia">
  <div class="r">
    <div class="sec-label-w">Presença na imprensa</div>
    <h2 class="sec-h-w" style="margin-bottom:48px;">Referência na grande <span class="t">imprensa brasileira.</span></h2>
  </div>
  <div class="r d1" style="display:flex;flex-wrap:wrap;align-items:center;border-top:1px solid rgba(255,255,255,0.08);padding-top:44px;gap:0;">
    <div style="flex:1;min-width:110px;padding:20px 28px;display:flex;align-items:center;justify-content:center;border-right:1px solid rgba(255,255,255,0.06);">
      <img src="https://www.datastore.com.br/novosite2026/forbes.png" alt="Forbes" style="height:38px;width:auto;max-width:110px;object-fit:contain;filter:brightness(0) invert(1);opacity:0.75;transition:opacity .2s;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.75">
    </div>
    <div style="flex:1;min-width:110px;padding:20px 28px;display:flex;align-items:center;justify-content:center;border-right:1px solid rgba(255,255,255,0.06);">
      <img src="https://www.datastore.com.br/novosite2026/veja.png" alt="Veja" style="height:38px;width:auto;max-width:110px;object-fit:contain;filter:brightness(0) invert(1);opacity:0.75;transition:opacity .2s;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.75">
    </div>
    <div style="flex:1;min-width:110px;padding:20px 28px;display:flex;align-items:center;justify-content:center;border-right:1px solid rgba(255,255,255,0.06);">
      <img src="https://www.datastore.com.br/novosite2026/exame.png" alt="Exame" style="height:48px;width:auto;max-width:130px;object-fit:contain;filter:brightness(0) invert(1);opacity:0.75;transition:opacity .2s;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.75">
    </div>
    <div style="flex:1;min-width:110px;padding:20px 28px;display:flex;align-items:center;justify-content:center;border-right:1px solid rgba(255,255,255,0.06);">
      <img src="https://www.datastore.com.br/novosite2026/logo-estadao.png" alt="Estadão" style="height:36px;width:auto;max-width:130px;object-fit:contain;filter:brightness(0) invert(1);opacity:0.75;transition:opacity .2s;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.75">
    </div>
    <div style="flex:1;min-width:130px;padding:20px 28px;display:flex;align-items:center;justify-content:center;border-right:1px solid rgba(255,255,255,0.06);">
      <img src="https://www.datastore.com.br/novosite2026/valor-economico.png" alt="Valor Econômico" style="height:44px;width:auto;max-width:150px;object-fit:contain;filter:brightness(0) invert(1);opacity:0.75;transition:opacity .2s;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.75">
    </div>
    <div style="flex:1;min-width:110px;padding:20px 28px;display:flex;align-items:center;justify-content:center;border-right:1px solid rgba(255,255,255,0.06);">
      <img src="https://www.datastore.com.br/novosite2026/jp.webp" alt="Jovem Pan" style="height:44px;width:auto;max-width:130px;object-fit:contain;filter:brightness(0) invert(1);opacity:0.75;transition:opacity .2s;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.75">
    </div>
    <div style="flex:1;min-width:110px;padding:20px 28px;display:flex;align-items:center;justify-content:center;border-right:1px solid rgba(255,255,255,0.06);">
      <img src="https://www.datastore.com.br/novosite2026/logo-agenciabrasil.svg" alt="Agência Brasil" style="height:44px;width:auto;max-width:140px;object-fit:contain;filter:brightness(0) invert(1);opacity:0.75;transition:opacity .2s;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.75">
    </div>
    <div style="flex:1;min-width:110px;padding:20px 28px;display:flex;align-items:center;justify-content:center;">
      <img src="https://www.datastore.com.br/novosite2026/revistavisao-branco.png" alt="Visão" style="height:44px;width:auto;max-width:130px;object-fit:contain;opacity:0.75;transition:opacity .2s;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=.75">
    </div>
  </div>
</section>

<!-- TIMELINE -->
<section class="timeline">
  <div class="tl-header">
    <div class="r">
      <div class="sec-label-w">Nossa trajetória</div>
      <h2 class="sec-h-w">Nosso <span class="t">pioneirismo.</span></h2>
    </div>
    <p class="tl-sub r d2">Da primeira pesquisa em Campinas ao <strong>maior bigdata</strong> imobiliário da <strong>América Latina</strong> — uma história construída com consistência e metodologia.</p>
  </div>
  <div class="tl-track">
    <div class="tl-line"></div>
    <div class="tl-item r">
      <div class="tl-year-col"><div class="tl-year">1994</div><div class="tl-dot"></div></div>
      <div class="tl-content"><div class="tl-title">Fundação da Datastore</div><div class="tl-text">Marcus Araujo, recém-formado em Estatística pela Unicamp, funda em Campinas a primeira empresa especializada em pesquisa de demanda imobiliária do Brasil.</div><span class="tl-tag">Campinas, São Paulo</span></div>
    </div>
    <div class="tl-item r">
      <div class="tl-year-col"><div class="tl-year">2000</div><div class="tl-dot"></div></div>
      <div class="tl-content"><div class="tl-title">Expansão para o Sudeste</div><div class="tl-text">A metodologia própria da Datastore ganha reconhecimento regional. A empresa começa a atender incorporadores em São Paulo, Minas Gerais e Espírito Santo.</div><span class="tl-tag">Região Sudeste</span></div>
    </div>
    <div class="tl-item r">
      <div class="tl-year-col"><div class="tl-year">2008</div><div class="tl-dot"></div></div>
      <div class="tl-content"><div class="tl-title">Atuação nacional</div><div class="tl-text">A Datastore ultrapassa as fronteiras regionais e passa a atuar em todas as cinco regiões do Brasil — tornando-se referência nacional em inteligência imobiliária.</div><span class="tl-tag">Brasil inteiro</span></div>
    </div>
    <div class="tl-item r">
      <div class="tl-year-col"><div class="tl-year">2014</div><div class="tl-dot"></div></div>
      <div class="tl-content"><div class="tl-title">Criação do algoritmo autoral</div><div class="tl-text">Marcus Araujo formaliza o Preditor de Velocidade de Vendas — um algoritmo exclusivo que combina os 8 fundamentos estatísticos em uma previsão objetiva do resultado de um lançamento.</div><span class="tl-tag">Algoritmo registrado · CONRE-SP 7691</span></div>
    </div>
    <div class="tl-item r">
      <div class="tl-year-col"><div class="tl-year">2018</div><div class="tl-dot"></div></div>
      <div class="tl-content"><div class="tl-title">Galleria Sky</div><div class="tl-text">A Datastore instala-se no Galleria Sky — ao lado da Unicamp, onde tudo começou. Um símbolo do tamanho que a empresa alcançou.</div><span class="tl-tag">Campinas · Ao lado da Unicamp</span></div>
    </div>
    <div class="tl-item r">
      <div class="tl-year-col"><div class="tl-year">2022</div><div class="tl-dot"></div></div>
      <div class="tl-content"><div class="tl-title">+1 trilhão em VGV pesquisado</div><div class="tl-text"><span class="dts-per-i">(1994–2024)</span> A Datastore ultrapassa a marca histórica — tornando-se o maior banco de dados sobre demanda imobiliária de compradores da América Latina.</div><span class="tl-tag">Maior bigdata da América Latina</span></div>
    </div>
    <div class="tl-item r">
      <div class="tl-year-col"><div class="tl-year" style="color:var(--blue)">2025</div><div class="tl-dot" style="background:var(--blue)"></div></div>
      <div class="tl-content"><div class="tl-title">+3.500 clientes. 85% do Brasil.</div><div class="tl-text">A Datastore segue consolidada como líder e pioneira do mercado imobiliário brasileiro. Seguimos transformando dados em decisões.</div><span class="tl-tag" style="color:var(--blue)">Presente · Em todo o Brasil</span></div>
    </div>
  </div>
</section>

<!-- VIDEO 32 ANOS -->
<div class="vid">
  <video autoplay muted loop playsinline preload="none">
    <source src="https://www.datastore.com.br/novosite2026/datastore1tri.mp4" type="video/mp4">
  </video>
  <div class="vid-overlay"></div>
  <div class="vid-body r">
    <div class="vid-label">Vídeo institucional</div>
    <h2 class="vid-h">Antecipando<br><span class="t">o mercado imobiliário.</span></h2>
    <button class="vid-btn">
      <span class="vid-play"><svg width="7" height="9" viewBox="0 0 7 9" fill="#000"><path d="M0 0l7 4.5-7 4.5z"/></svg></span>
      Assistir ao filme
    </button>
  </div>
</div>
<div class="vid-mobile-caption">
  <div class="vid-label">Vídeo institucional</div>
  <h2 class="vid-h">Antecipando<br><span class="t">o mercado imobiliário.</span></h2>
  <button class="vid-btn">
    <span class="vid-play"><svg width="7" height="9" viewBox="0 0 7 9" fill="#000"><path d="M0 0l7 4.5-7 4.5z"/></svg></span>
    Assistir ao filme
  </button>
</div>

<!-- EQUIPE -->
<section style="background:#FFFFFF;color:#0A0A0A;padding:96px 0 0;">

  <!-- Título e texto -->
  <div style="padding:0 60px 40px;" class="r">
    <div style="font-weight:400;font-size:13px;letter-spacing:0.06em;color:#999;margin-bottom:12px;">Diretoria Executiva</div>
    <h2 style="font-weight:700;font-size:clamp(30px,3.8vw,52px);line-height:0.96;letter-spacing:-0.05em;color:#0A0A0A;margin-bottom:16px;">
      Excelência e compromisso<br><span style="font-weight:300;color:#666;">em cada empreendimento.</span>
    </h2>
    <p style="font-weight:400;font-size:16px;line-height:1.72;color:#444;max-width:680px;">
      Os Diretores Comerciais da Datastore são profissionais selecionados e franqueados por Marcus Araujo na visão estratégica da empresa. Atuam em todo o território brasileiro com o mesmo nível de exigência e compromisso com o resultado.
    </p>
  </div>

  <!-- DIRETORIA — foto + faixa preta de legendas embaixo (desktop) / imagem vertical (mobile) -->
  <style>
    .dts-team-wrap{width:calc(100% - 120px);margin:0 60px;}
    .dts-team-img{width:100%;height:auto;display:block;background:#FFFFFF;}
    .dts-team-img-mobile{display:none;}
    .dts-team-bar{background:#0A0A0A;margin-left:calc(50% - 50vw);width:100vw;padding:22px 0 24px;position:relative;}
    .dts-team-grid{position:relative;max-width:calc(100% - 120px);margin:0 auto;height:92px;}
    .dts-team-cell{position:absolute;top:0;text-align:center;transform:translateX(-50%);width:150px;}
    .dts-team-name{font-weight:700;font-size:13px;color:#FFFFFF;line-height:1.3;}
    .dts-team-role{font-weight:400;font-size:11px;line-height:1.4;color:rgba(255,255,255,0.75);margin-top:2px;}
    .dts-team-badge{display:inline-block;margin-top:6px;font-weight:500;font-size:8px;color:#FFF;padding:2px 8px;border-radius:100px;}
    @media (max-width:860px){
      .dts-team-wrap{width:100%;margin:0;}
      .dts-team-img{display:none;}
      .dts-team-img-mobile{display:block;position:relative;left:50%;transform:translateX(-50%);width:100vw;max-width:100vw;height:auto;background:#FFFFFF;}
      .dts-team-bar{position:relative;left:50%;transform:translateX(-50%);margin-left:0;width:100vw;padding:14px 0 16px;}
      .dts-team-grid{max-width:calc(100% - 12px);height:64px;}
      .dts-team-cell{width:80px;}
      .dts-team-name{font-size:8px;line-height:1.25;white-space:normal;word-break:break-word;}
      .dts-team-role{font-size:6.5px;line-height:1.3;white-space:normal;word-break:break-word;margin-top:1px;}
      .dts-team-badge{font-size:5.5px;padding:1px 4px;margin-top:3px;}
    }
  </style>
  <div class="dts-team-wrap r d1">
    <img class="dts-team-img" src="https://datastore.com.br/novosite2026/fotos-executivos-2026.png" alt="Diretoria Executiva Datastore">
    <img class="dts-team-img-mobile" src="https://datastore.com.br/novosite2026/fotos-executivos-2026-mobile.png" alt="Diretoria Executiva Datastore">
    <div class="dts-team-bar">
      <div class="dts-team-grid">
      <div class="dts-team-cell" style="left:15.7%;">
        <div class="dts-team-name">Edvaldo Corrêa</div>
        <div class="dts-team-role">Diretor Comercial</div>
      </div>
      <div class="dts-team-cell" style="left:27.4%;">
        <div class="dts-team-name">Izildo Castilho Jr</div>
        <div class="dts-team-role">Diretor Comercial</div>
      </div>
      <div class="dts-team-cell" style="left:42.3%;">
        <div class="dts-team-name">Marcus Araujo</div>
        <div class="dts-team-role">Estatístico e Fundador</div>
        <span class="dts-team-badge" style="background:#5FB352;">CONRE-SP 7691</span>
      </div>
      <div class="dts-team-cell" style="left:57.7%;">
        <div class="dts-team-name">Samuel Araujo</div>
        <div class="dts-team-role">CEO Datastore</div>
      </div>
      <div class="dts-team-cell" style="left:70.9%;">
        <div class="dts-team-name">Cristiane Uetanabara</div>
        <div class="dts-team-role">Dir. Técnica · Estatística</div>
        <span class="dts-team-badge" style="background:#335DA8;">CONRE-SP 7945</span>
      </div>
      <div class="dts-team-cell" style="left:81.0%;">
        <div class="dts-team-name">Rafael Vilela</div>
        <div class="dts-team-role">Diretor Comercial</div>
      </div>
      </div>
    </div>
  </div>


</section>

<!-- SEDE -->
<div class="sede">
  <img src="https://www.datastore.com.br/novosite2026/sky-galeria.jpeg" alt="Galleria Sky — Campinas">
  <div class="sede-overlay"></div>
  <div class="sede-body">
    <h2 class="sede-h r">Nossa sede em<br><span class="t">Campinas, São Paulo.</span></h2>
    <p class="sede-p r d2">Instalada no Galleria Sky, ao lado da Unicamp — o maior centro de produção científica da <strong>América Latina</strong>. Inteligência precisa de raízes sólidas.</p>
  </div>
</div>

<!-- MISSÃO / VISÃO / VALORES -->
<div class="mvv">
  <div class="mvv-block r">
    <div class="mvv-label">Missão</div>
    <div class="mvv-h">Oferecer suporte, inteligência e segurança</div>
    <p class="mvv-p">Para os empreendedores em todas as fases do desenvolvimento de um produto imobiliário — desde a escolha do terreno até o ajuste fino do mix de produtos.</p>
  </div>
  <div class="mvv-block r d1">
    <div class="mvv-label">Visão</div>
    <div class="mvv-h">O maior ecossistema de inteligência imobiliária</div>
    <p class="mvv-p">Um ecossistema de inteligência, pesquisa, conteúdo, formação e autoridade para o mercado imobiliário — transformando cada empreendimento em uma decisão segura.</p>
  </div>
  <div class="mvv-block r d2">
    <div class="mvv-label">Valores</div>
    <div class="mvv-h">Dados, rigor e compromisso com o resultado</div>
    <p class="mvv-p">Decisões baseadas em evidências. Metodologia registrada. Transparência com os clientes. Presença nacional. E o compromisso absoluto com o sucesso do empreendimento.</p>
  </div>
</div>

<!-- CTA -->

<section class="cta">
  <div class="r">
    <h2 class="cta-h">Conheça a Datastore<br><span class="t">de perto.</span></h2>
  </div>
  <div class="r d2">
    <p class="cta-p">Fale com um de nossos executivos. Entendemos o seu momento de negócio e mostramos como a inteligência Datastore pode transformar o seu próximo empreendimento.</p>
    <div class="cta-actions">
      <a href="https://wa.me/5519983830418" class="btn-wpp">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a5.48 5.48 0 00-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479s1.065 2.875 1.213 3.074c.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/></svg>
        WhatsApp
      </a>
      <a href="<?php echo home_url('/pesquisas/'); ?>" class="btn-outline">Ver pesquisas</a>
    </div>
  </div>
</section>

<!-- video_sobre_marker -->

<!-- VÍDEO SOBRE -->
<section style="background:var(--black);padding:96px 60px;">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:64px;align-items:center;">
    <div class="r">
      <div style="font-weight:400;font-size:13px;letter-spacing:0.06em;color:var(--silver);margin-bottom:16px;">Nossa história</div>
      <h2 style="font-weight:700;font-size:clamp(26px,3.2vw,44px);line-height:0.95;letter-spacing:-0.05em;color:var(--pure);margin-bottom:20px;">
        Antecipando<br><span style="font-weight:300;color:var(--mist)">o mercado imobiliário.</span>
      </h2>
      <p style="font-weight:400;font-size:16px;line-height:1.72;color:var(--silver);max-width:420px;">
        Desde 1994, a Datastore constrói um banco de dados único sobre o comportamento do comprador de imóveis no Brasil — e usa esse conhecimento para dar inteligência ao empreendedor.
      </p>
    </div>
    <div class="r d1" style="position:relative;border-radius:var(--r-lg);overflow:hidden;background:#0A0A0A;">
      <video autoplay muted loop playsinline preload="none" style="width:100%;display:block;border-radius:var(--r-lg);">
        <source src="https://www.datastore.com.br/novosite2026/datastore1tri.mp4" type="video/mp4">
      </video>
      <div style="position:absolute;bottom:0;left:0;right:0;background:linear-gradient(to top,rgba(10,10,10,.7) 0%,transparent 60%);padding:24px;pointer-events:none;">
        <div style="font-weight:700;font-size:16px;color:#fff;letter-spacing:-0.02em;">+R$ 1,5 trilhão</div>
        <div style="font-weight:400;font-size:13px;color:rgba(255,255,255,0.55);margin-top:4px;">em VGV pesquisado</div>
      </div>
    </div>
  </div>
</section>

<?php dts_blog_roll( 'artigos', -1, 'Artigos' ); ?>


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
