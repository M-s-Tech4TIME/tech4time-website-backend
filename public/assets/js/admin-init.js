/* ==========================================================================
   Tech4TIME — admin-init.js
   Starts the admin page's modules.

   A separate file rather than an inline <script> because the CSP is
   script-src 'self'. It is the same reason theme-init.js exists.
   ========================================================================== */

(function (global) {
  "use strict";

  /* ONE MODULE FAILING MUST NOT TAKE THE REST WITH IT.

     Two documents said this file already did that. It did not — the calls were
     bare, so anything thrown by the first of them stopped every one after it.
     The order below is the order of dependence, which makes that the worst
     possible arrangement: a throw in the rich-text editor would have left the
     forms and the links unwired, and every one of them a full page load, with
     nothing on screen to say why.

     Caught per module and reported, rather than caught around the whole set.
     The point is that the others still start. */
  function begin(name, module) {
    if (!module || typeof module.init !== "function") {
      return;
    }

    try {
      module.init();
    } catch (error) {
      /* The console is the right place: there is nothing a person reading the
         page can do about it, and the module that failed is the one whose
         enhancement is simply absent. The page still works — that is the
         whole bargain of building it this way. */
      if (global.console && global.console.error) {
        global.console.error("Tech4Time: " + name + " did not start.", error);
      }
    }
  }

  function start() {
    var api = global.Tech4Time;
    if (!api) {
      return;
    }

    /* theme-init.js has already set data-theme before first paint; this wires
       up the button that changes it. */
    begin("theme", api.theme);
    /* Owns one field and nothing depends on it, so it sits outside the chain
       below: if it throws, the rail and the editors still start. */
    begin("adminPassword", api.adminPassword);
    begin("adminNav", api.adminNav);
    begin("editor", api.editor);
    begin("adminOutline", api.adminOutline);
    begin("adminToast", api.adminToast);
    /* Before admin-forms.js, which asks this one whether it can work: with the
       swap module absent every form navigates, which is the fallback. */
    begin("adminSwap", api.adminSwap);
    /* Last, and it does not matter: its listener is on the document, so the
       per-form handlers editor.js just bound still run before it. */
    begin("adminForms", api.adminForms);
  }

  if (global.document.readyState === "loading") {
    global.document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})(window);
