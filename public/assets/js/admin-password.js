/* ==========================================================================
   Tech4TIME — admin-password.js
   The show/hide switch on a password field.

   A separate file rather than an inline <script> because the CSP is
   script-src 'self'. It is the same reason theme-init.js exists.

   WHAT IT DOES AND DOES NOT DO
   The button is in the markup already, hidden, with the room it needs reserved
   by .admin__password in admin.css. This module unhides it and wires it. So
   with no script the field is an ordinary password box and there is no dead
   control on screen, which is the rule every enhancement here follows.

   It flips the input's `type`, which is the only method that works in every
   browser: -webkit-text-security is neither standard nor implemented in
   Firefox. Some password managers watch for that flip, which is why the
   input's id, name and autocomplete are left exactly as they were.

   THE CARET IS PUT BACK BY HAND
   Changing `type` re-creates the control's inner text field, and a browser is
   entitled to drop the selection when it does -- Safari and Firefox both send
   the caret to the end. Somebody who reveals a password to check one character
   in the middle should not have to find their place again, so the selection is
   read before the flip and written back after it.

   HOW IT SAYS WHICH STATE IT IS IN
   A changing name plus aria-pressed, which is what the theme switch in
   admin_head() does. Deliberately the same: two toggles in one panel that
   announce themselves differently is a worse problem than any argument about
   which of the two conventions is purer.

   WHY init() IS SAFE TO CALL TWICE
   Every field on the account screen lives inside #admin-main, which
   admin-swap.js replaces on each move between screens and admin-forms.js
   replaces after each async save -- so this runs again every time, and a
   second listener on a surviving button would flip the type twice per press
   and leave a switch that visibly does nothing.

   The mark that says "already wired" is the `hidden` attribute the markup
   ships and wire() removes, so the selector below asks for exactly the buttons
   that have not been through here yet. Fresh markup arrives hidden and is
   wired; a survivor is visible and is skipped. Same idea as editor.js's
   :not(.rte__source), and with no second attribute to keep in step.

   Exposes window.Tech4Time.adminPassword for admin-init.js to start, and for
   admin-swap.js to start again.
   ========================================================================== */

(function (global) {
  "use strict";

  var doc = global.document;

  /* [hidden] is the filter, not decoration -- see WHY init() IS SAFE above. */
  var TOGGLE = "[data-password-toggle][hidden]";

  var LABEL = {
    show: "Show password",
    hide: "Hide password"
  };

  function apply(button, input, shown) {
    var start = null;
    var end = null;

    /* Only a text-ish control has a selection to preserve, and reading these
       off one that does not throws in some browsers. */
    try {
      start = input.selectionStart;
      end = input.selectionEnd;
    } catch (error) {
      start = null;
    }

    input.type = shown ? "text" : "password";

    if (start !== null && doc.activeElement === input) {
      try {
        input.setSelectionRange(start, end);
      } catch (error) {
        /* Nothing to put back; the caret stays wherever the browser left it. */
      }
    }

    var label = shown ? LABEL.hide : LABEL.show;
    button.setAttribute("aria-label", label);
    button.setAttribute("title", label);
    button.setAttribute("aria-pressed", shown ? "true" : "false");
  }

  function wire(button) {
    var input = doc.getElementById(button.getAttribute("data-password-toggle"));
    if (!input || input.tagName.toLowerCase() !== "input") {
      /* The field it names is not there. Leave the button hidden rather than
         showing a control that would do nothing -- and leaving it hidden also
         leaves it in the set this module looks at, which is correct: it has
         not been wired. */
      return;
    }

    button.hidden = false;
    apply(button, input, false);

    button.addEventListener("click", function (event) {
      apply(button, input, input.type === "password");

      /* THE POINTER GETS THE FOCUS BACK AND THE KEYBOARD DOES NOT, and the
         asymmetry is the point. A click puts the focus on the button, so
         somebody who revealed the password mid-word would have to click back
         into the field to carry on typing -- hence the move. But a keyboard
         activation goes through the same handler, and moving the focus there
         means Space reveals the password and then walks away from the switch,
         so hiding it again needs Shift+Tab first. A toggle the keyboard cannot
         press twice in a row is a worse fault than a click that has to be
         made twice.

         detail is the click count: 0 for a click the keyboard synthesised,
         1 or more for one a pointer actually made. */
      if (event.detail > 0) {
        input.focus();
      }
    });

    /* Never leave a password on screen once the form is on its way. The page
       navigates, so this matters for the back button and for a submission the
       browser cancels. */
    if (button.form) {
      button.form.addEventListener("submit", function () {
        apply(button, input, false);
      });
    }
  }

  global.Tech4Time = global.Tech4Time || {};
  global.Tech4Time.adminPassword = {
    init: function () {
      Array.prototype.forEach.call(doc.querySelectorAll(TOGGLE), wire);
    }
  };
})(window);
