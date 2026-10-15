// En arribar al peu, el botó flotant "Demana pressupost" s'atura sobre la línia
// divisòria en comptes de continuar enganxat a baix tapant contactes i copyright.
// El carrega Footer.dc.html, així que val per a totes les pàgines.
(function () {
  var GAP = 12;   // espai entre el botó i la línia del peu
  var BASE = 18;  // separació habitual respecte al final de la finestra
  var pendent = false, cta, linia;

  function colloca() {
    pendent = false;
    if (!cta || !cta.isConnected) cta = document.getElementById("float-cta");
    if (!linia || !linia.isConnected) linia = document.getElementById("footer-cols");
    if (!cta || !linia) return;
    var y = linia.getBoundingClientRect().bottom;     // on és la línia del peu
    var sobreLaLinia = window.innerHeight - y + GAP;  // quant cal pujar el botó
    var sostre = window.innerHeight - cta.offsetHeight - BASE;
    cta.style.bottom = Math.max(BASE, Math.min(sobreLaLinia, sostre)) + "px";
  }

  function demana() {
    if (!pendent) { pendent = true; requestAnimationFrame(colloca); }
  }

  window.addEventListener("scroll", demana, { passive: true });
  window.addEventListener("resize", demana);
  window.addEventListener("load", demana);

  if (document.getElementById("float-cta")) colloca();
  else {
    // El peu es carrega com a component: espera que hi sigui.
    var mo = new MutationObserver(function () {
      if (document.getElementById("float-cta") && document.getElementById("footer-cols")) {
        mo.disconnect();
        colloca();
      }
    });
    mo.observe(document.documentElement, { childList: true, subtree: true });
  }
})();
