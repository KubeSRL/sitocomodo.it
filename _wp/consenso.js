(function(){
  var PIXEL = '1131728194134695', BANNER = false, CHIAVE = 'sitocomodo_consenso', SEI_MESI = 182 * 864e5;
  function leggi(){ try { var c = JSON.parse(localStorage.getItem(CHIAVE) || 'null'); return c && Date.now() - c.quando < SEI_MESI ? c.scelta : null; } catch(e) { return null; } }
  function salva(s){ try { localStorage.setItem(CHIAVE, JSON.stringify({scelta: s, quando: Date.now()})); } catch(e) {} }

  var avviato = false;
  function avviaPixel(){
    if (avviato || !PIXEL) return; avviato = true;
    !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};
    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', PIXEL);
    fbq('track', 'PageView');
    var p = location.pathname;
    if (/\/ordina\/?$/.test(p)) fbq('track', 'ViewContent', {value: 99, currency: 'EUR', content_name: 'Sito vetrina'});
    if (/\/ordine\/?$/.test(p)) {
      var dom = ''; try { dom = (JSON.parse(localStorage.getItem('sitocomodo_ordine') || '{}').dominio) || ''; } catch(e) {}
      if (dom || new URLSearchParams(location.search).get('dominio')) fbq('track', 'AddToCart', {value: 99, currency: 'EUR', content_name: 'Sito vetrina'});
    }
    if (/\/checkout\/?$/.test(p)) fbq('track', 'InitiateCheckout', {value: 99, currency: 'EUR', content_name: 'Sito vetrina'});
    if (/\/grazie\/?$/.test(p)) {
      var sid = new URLSearchParams(location.search).get('session_id');
      if (sid) {
        var fatti = []; try { fatti = JSON.parse(localStorage.getItem('sitocomodo_acquisti') || '[]'); } catch(e) {}
        if (fatti.indexOf(sid) < 0) {
          fbq('track', 'Purchase', {value: 99, currency: 'EUR', content_name: 'Sito vetrina'}, {eventID: sid});
          fatti.push(sid); try { localStorage.setItem('sitocomodo_acquisti', JSON.stringify(fatti.slice(-20))); } catch(e) {}
        }
      }
    }
  }

  var banner;
  function chiudi(){ if (banner) { banner.remove(); banner = null; } }
  function scegli(s){ salva(s); chiudi(); if (s === 'si') avviaPixel(); else if (avviato) location.reload(); }
  function apri(){
    if (banner) return;
    var st = document.getElementById('sc-cookie-stile');
    if (!st) {
      st = document.createElement('style'); st.id = 'sc-cookie-stile';
      st.textContent = '#sc-cookie{position:fixed;left:16px;right:16px;bottom:16px;z-index:9999;max-width:560px;margin:0 auto;background:#FFFFFF;color:#111827;border:2px solid #111827;border-radius:20px;box-shadow:6px 6px 0 #111827;padding:20px 22px;font:16px/1.5 Inter,system-ui,sans-serif}'
        + '#sc-cookie h2{margin:0 0 6px;font:700 19px/1.25 "Space Grotesk",Inter,sans-serif}'
        + '#sc-cookie p{margin:0;color:#374151;font-size:15px}'
        + '#sc-cookie a{color:#1D4ED8}'
        + '#sc-cookie .sc-scelte{display:flex;gap:12px;margin-top:16px}'
        + '#sc-cookie button{flex:1 1 0;min-height:48px;border-radius:14px;border:2px solid #111827;background:#FFFFFF;color:#111827;font:700 16px Inter,system-ui,sans-serif;cursor:pointer}'
        + '#sc-cookie button:hover{background:#FEF3C7}'
        + '#sc-cookie button:focus-visible,#sc-cookie a:focus-visible{outline:3px solid #F97316;outline-offset:2px}'
        + '@media (max-width:480px){#sc-cookie{left:10px;right:10px;bottom:10px;padding:16px;box-shadow:4px 4px 0 #111827}}';
      document.head.appendChild(st);
    }
    banner = document.createElement('section');
    banner.id = 'sc-cookie';
    banner.setAttribute('role', 'region');
    banner.setAttribute('aria-labelledby', 'sc-cookie-titolo');
    banner.innerHTML = '<h2 id="sc-cookie-titolo">Due parole sui cookie</h2>'
      + '<p>Se accetti, usiamo i cookie di Meta (Facebook e Instagram) per capire quali nostre pubblicità funzionano. Se rifiuti, il sito va benissimo lo stesso. '
      + '<a href="/privacy/">Privacy e cookie</a></p>'
      + '<div class="sc-scelte"><button type="button" data-scelta="no">Rifiuta</button><button type="button" data-scelta="si">Accetta</button></div>';
    banner.addEventListener('click', function(e){ var b = e.target.closest('[data-scelta]'); if (b) scegli(b.getAttribute('data-scelta')); });
    document.body.appendChild(banner);
  }

  window.scCookie = {apri: apri};
  document.addEventListener('click', function(e){ var a = e.target.closest('[data-cookie]'); if (a && PIXEL && BANNER) { e.preventDefault(); apri(); } });
  if (!PIXEL) return;               // senza Pixel non usiamo cookie: niente banner
  if (!BANNER) { avviaPixel(); return; }   // il banner lo mette Giuseppe: qui il Pixel parte subito
  var s = leggi();
  if (s === 'si') avviaPixel();
  else if (s === null) { if (document.body) apri(); else document.addEventListener('DOMContentLoaded', apri); }
})();
