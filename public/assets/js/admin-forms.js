/* ==========================================================================
   Tech4TIME — admin-forms.js
   Submits the editors without throwing the page away.

   WHAT IT IS FOR
   Every control in these editors is a submit button: adding a row, removing
   one, moving one up, saving. Each of those was a full navigation, and a full
   navigation lands at the top of the document. On the company profile — ten
   bands, 282 rows, 448 fields — that meant pressing "Move down" on a client
   logo and arriving back at the page title, several thousand pixels away from
   the thing you were arranging. Doing it twice was a scroll each way. The
   effect was that the page appeared to contain only its first field group,
   because that is the only part anybody ever saw.

   This posts the same form to the same URL, puts the answer back in place,
   and leaves the scroll position and the focus where they were.

   IT CHANGES NOTHING ON THE SERVER
   Deliberately. The response is the ordinary page: the same PHP, the same
   redirect-after-save, the same markup. fetch() follows the redirect exactly
   as the browser would, and what comes back is swapped into <main>. So there
   is no second rendering path to keep in step with the first, no JSON schema
   to version, and nothing that can be true of one and false of the other.
   Delete this file and every button still works, by navigating — which is the
   hard rule this project holds to, and the reason it was built this way.

   WHY NOT innerHTML ON THE WHOLE DOCUMENT
   The rail carries the open/closed state of the account menu and the width the
   person chose. Only #admin-main changes between these responses, so only
   #admin-main is replaced.

   THE SWAP ITSELF IS admin-swap.js, which does the same job for the links.
   Putting the answer back in place is one problem with one answer, and it was
   written here first only because the buttons were done first. What is left in
   this file is what is particular to a form: which button was pressed, what
   had focus, and where to put it back afterwards.
   ========================================================================== */

(function (global) {
  "use strict";

  var doc = global.document;

  var MAIN = "admin-main";
  var BUSY = "admin__status--busy";
  var BAD = "admin__status--bad";

  /* Everything this needs. Any one of them missing and the forms are left
     alone, which means they navigate — the behaviour this replaces. */
  function usable() {
    var api = global.Tech4Time;

    return (
      typeof global.fetch === "function" &&
      typeof global.FormData === "function" &&
      !!(api && api.adminSwap && api.adminSwap.usable()) &&
      global.history &&
      typeof global.history.replaceState === "function"
    );
  }

  /* ------------------------------------------------------------- the notice */

  function status(text, className) {
    global.Tech4Time.adminSwap.status(text, className);
  }

  /* -------------------------------------------- where the form actually posts

     NOT form.action, and the reason is a line of the HTML specification rather
     than anything about this project. HTMLFormElement is declared
     [LegacyOverrideBuiltIns], so a control's name wins over the interface's own
     property of that name: a form containing <input name="action"> answers
     form.action with that INPUT, not with a URL. String() then turns it into
     "[object HTMLInputElement]", fetch() resolves that against the page, and
     the request goes to /[object%20HTMLInputElement].

     Every job-post form has such a control — sections/careers.php reads
     $_POST['action'] to tell save from delete from move — so every button on
     the careers screen posted to an address no server has ever served and got
     a 404 back, once per press. Nothing else on the site was affected, because
     careers is the only section with a control named after a form property
     that anything reads -- id, name and title are shadowed on several screens,
     and nothing looks at them.
     Ordinary submission was never affected either: the browser submits from
     the ATTRIBUTE and never consults the shadowed property, which is why this
     appeared on the day the forms started posting themselves and not on the
     day the field was added.

     The getter on the prototype is exactly the property the shadowing hides,
     and it already carries the whole of the specification's behaviour: the
     attribute resolved against the document's base URL, and the document's own
     URL when there is no attribute. So there is nothing to reimplement here
     and nothing that can drift from what a plain <form> would have done.

     The same shadowing hides id, name, method, target, elements, submit and
     reset behind a control of that name. Nothing in this file reads any of
     them, and tools/check_form_dom.py is there to keep that true. */
  var FORM_ACTION = global.HTMLFormElement && Object.getOwnPropertyDescriptor
    ? Object.getOwnPropertyDescriptor(global.HTMLFormElement.prototype, "action")
    : null;

  function postUrl(form) {
    if (FORM_ACTION && typeof FORM_ACTION.get === "function") {
      return FORM_ACTION.get.call(form) || global.location.href;
    }

    /* No descriptor: work the two answers out by hand. A browser this old has
       no fetch() either, so usable() has already left the forms alone. */
    var attr = form.getAttribute("action");

    if (attr && typeof global.URL === "function") {
      return new global.URL(attr, doc.baseURI).href;
    }

    return global.location.href;
  }

  /* ------------------------------------------------- where to look afterwards

     A reorder renumbers the rows it moves between, so the button that was
     pressed is no longer the button for that row: press "down" on row 3 and
     the row is now row 4. Following it means a second press does what the
     first appeared to promise, which is what makes arranging fifty logos with
     the keyboard possible at all.

     Anything not understood here falls back to the field with the same name,
     and failing that to nothing. Guessing wrongly costs a focus ring; it
     cannot lose an edit. */
  function nextFocus(submitter) {
    if (!submitter || submitter.name !== "do") {
      return null;
    }

    /* The address may be nested: "card-remove:1.4" is the fifth solution of
       the second group. Only the LAST coordinate moves, so the leading ones
       are carried along untouched. A flat "story-up:3" is the same shape with
       nothing in front of it. */
    var parts = /^([a-z]+)-(up|down|add|remove):(\d+(?:\.\d+)*)$/.exec(submitter.value || "");
    if (!parts) {
      return null;
    }

    var band = parts[1];
    var action = parts[2];
    var address = parts[3].split(".");
    var index = parseInt(address[address.length - 1], 10);
    var stem = address.slice(0, -1).join(".");
    if (stem !== "") {
      stem += ".";
    }

    function at(n) {
      return band + "-" + action + ":" + stem + n;
    }

    if (action === "up") {
      return { selector: 'button[name="do"][value="' + at(Math.max(0, index - 1)) + '"]' };
    }
    if (action === "down") {
      return { selector: 'button[name="do"][value="' + at(index + 1) + '"]' };
    }
    if (action === "add") {
      /* The new row is the last one in its band; its first text field is
         where somebody is about to type.

         WHICH rows those are is normally "<band>[items][", and for a nested
         list it is not — a solution card is service[layers][items][0][cards][.
         The button says so with data-rows when the two differ, rather than
         this having to know the shape of every editor. */
      return {
        lastRowOf: band,
        rows: submitter.getAttribute("data-rows") || ""
      };
    }
    /* Removed. The row that took its place, or the one before it if the list
       just lost its tail. */
    return {
      selector:
        'button[name="do"][value="' + at(index) + '"], ' +
        'button[name="do"][value="' + at(Math.max(0, index - 1)) + '"]'
    };
  }

  /* What had focus, and where the page was. */
  function snapshot() {
    var active = doc.activeElement;
    var shot = { scroll: global.scrollY || global.pageYOffset || 0, name: "", start: null, end: null };

    if (active && active.name && active.form) {
      shot.name = active.name;
      try {
        shot.start = active.selectionStart;
        shot.end = active.selectionEnd;
      } catch (error) {
        /* Not a field with a caret — a select, a checkbox, a button. */
      }
    }

    return shot;
  }

  function focusFirstFieldOf(band, namedRows) {
    var prefix = namedRows || band + "[items][";
    var rows = doc.querySelectorAll('input[name^="' + prefix + '"]');
    if (!rows.length) {
      return false;
    }

    /* The last row's first field that somebody can actually type in: the id
       is a hidden input and would swallow the focus silently. */
    var last = rows[rows.length - 1].closest(".admin-card");
    var field = last && last.querySelector("input:not([type=hidden]), textarea, select");

    if (field) {
      field.focus();
      field.scrollIntoView({ block: "center" });
      return true;
    }

    return false;
  }

  function restore(shot, plan) {
    if (plan && plan.lastRowOf && focusFirstFieldOf(plan.lastRowOf, plan.rows)) {
      return;
    }

    if (plan && plan.selector) {
      var target = doc.querySelector(plan.selector);
      if (target) {
        global.scrollTo(0, shot.scroll);
        target.focus();
        return;
      }
    }

    global.scrollTo(0, shot.scroll);

    if (!shot.name) {
      return;
    }

    var field = doc.querySelector('[name="' + shot.name.replace(/"/g, '\\"') + '"]');
    if (!field || typeof field.focus !== "function") {
      return;
    }

    field.focus();

    if (shot.start !== null && typeof field.setSelectionRange === "function") {
      try {
        field.setSelectionRange(shot.start, shot.end);
      } catch (error) {
        /* A field type that has no caret. Focus is the part that mattered. */
      }
    }
  }

  /* ------------------------------------------------------------- the swap

     admin-swap.js does the putting-back, for the links as well as for this.
     Two things are said here rather than there, because they are true of a
     form post and not of a move between screens:

       #admin-main and not #admin-body — the bar holds the status line that
       has just said "Working…", and replacing it would wipe the only report
       of what is happening.

       replaceState and not pushState — a save is not a place. Adding a
       history entry for it would mean Back walked through every row that was
       added and every one that was moved. */

  function swap(html, url) {
    return global.Tech4Time.adminSwap.apply(html, url, {
      region: MAIN,
      entry: "replace"
    });
  }

  /* ------------------------------------------------- problems worth moving for

     A NEW problem, and only a new one. This used to jump to the first error or
     warning on the page, whatever it was and however long it had been there —
     and the contact editor carries a standing warning, shown whenever the
     site's footers have drifted from the record, which is most of the time.
     So every add, every remove, every move and every save on that screen
     scrolled to a notice at the top and never restored the position. It was
     indistinguishable from a full page reload, and it was reported as one.

     Nothing about it was visible locally: the development copy of
     content/contact.json is in step, so the warning is not there to be found.

     Compared by text rather than by class, because the class cannot tell the
     two apart — a failed publish is a --warn that IS the answer to what was
     just pressed, and the footer drift is a --warn that is not. What was
     already on the page before the press is what separates them. */
  var PROBLEM = ".admin__notice--error, .admin__notice--warn";

  function problemsNow() {
    return Array.prototype.map.call(
      doc.querySelectorAll(PROBLEM),
      function (notice) { return notice.textContent.replace(/\s+/g, " ").trim(); }
    );
  }

  function showProblem(standing) {
    var found = doc.querySelectorAll(PROBLEM);

    for (var i = 0; i < found.length; i += 1) {
      var text = found[i].textContent.replace(/\s+/g, " ").trim();
      if (standing.indexOf(text) === -1) {
        found[i].scrollIntoView({ block: "center" });
        return true;
      }
    }

    return false;
  }

  /* --------------------------------------------------------------- sending */

  /* data-confirm asks first — "Delete this post permanently?" and its like.
     The admin's own box now, which is asynchronous, so the sending is a
     separate function rather than the rest of this one. */
  function send(form, submitter) {
    var confirmation = form.getAttribute("data-confirm");

    if (!confirmation) {
      post(form, submitter);
      return;
    }

    var dialog = global.Tech4Time.adminDialog;
    var question = dialog
      ? dialog.ask({
          title: confirmation,
          message: "This cannot be undone.",
          confirm: "Delete",
          cancel: "Keep it",
          tone: "danger"
        })
      : { then: function (fn) { fn(global.confirm(confirmation)); return this; } };

    question.then(function (yes) {
      if (yes) {
        post(form, submitter);
      }
    });
  }

  function post(form, submitter) {
    var body = new global.FormData(form);

    /* FormData never includes submit buttons, so the one that was pressed has
       to be added by hand — and it is the whole instruction here: "do" is what
       says save, or add, or move this row up. */
    if (submitter && submitter.name) {
      body.append(submitter.name, submitter.value);
    }

    var shot = snapshot();
    var plan = nextFocus(submitter);
    var standing = problemsNow();

    form.setAttribute("aria-busy", "true");
    if (submitter) {
      submitter.disabled = true;
    }
    status("Working…", BUSY);

    /* Undoing the busy state, on EVERY path out of here and not only the
       failing one.

       Most of the buttons that submit this form live inside #admin-main and
       are therefore replaced wholesale by the swap, arriving fresh and
       enabled — which is why this was invisible for so long. The page-level
       Save button does not: admin_head() renders it in the title bar, ABOVE
       <main>, and wires it to the form with the HTML form= attribute. The swap
       never touches it, so a disable that was never undone left it dead until
       the operator navigated away or reloaded.

       Re-enabling a node the swap has already detached is harmless, so this
       does not need to know which kind of button it was holding. */
    function idle() {
      form.removeAttribute("aria-busy");
      if (submitter) {
        submitter.disabled = false;
      }
    }

    /* WHAT TO DO NEXT DEPENDS ON WHAT WENT WRONG, and this said "press again"
       whatever had happened. It was reported from the live editor with four
       identical 404 toasts stacked in the corner -- one per press, because the
       message asked for each of them and none of them could ever have worked.

       A 404 or a 405 is the server saying this address is not one it serves.
       Repeating the request repeats the answer. A 403 here is a rejected CSRF
       token, which a fresh page fixes and a retry does not. Only a 5xx and a
       dropped connection are worth pressing again for. */
    function whatNext(error) {
      var code = error && error.status;

      if (code === 404 || code === 405) {
        return "Nothing was lost, and pressing again will not help: the " +
               "address this form posts to is not one the server answers. " +
               "Reload the page. If it happens again, the site is misconfigured.";
      }
      if (code === 403 || code === 419) {
        return "Nothing was lost. Reload the page and try once more — the " +
               "sign-in may have expired while this screen was open.";
      }
      if (code >= 400 && code < 500) {
        return "Nothing was lost, but the server refused the request as it " +
               "was made. Reload the page.";
      }
      return "Nothing was lost; press again.";
    }

    global
      .fetch(postUrl(form), {
        method: "POST",
        body: body,
        credentials: "same-origin",
        redirect: "follow",
        headers: { "X-Requested-With": "fetch" }
      })
      .then(function (response) {
        var url = response.url || postUrl(form);

        /* A session that has ended redirects to the sign-in page. Swapping
           that into <main> would leave somebody typing into a form that is no
           longer attached to anything. */
        if (/\/(login|setup|reset|forgot)\.php/.test(url)) {
          global.location.href = url;
          return null;
        }

        if (!response.ok) {
          var refused = new Error("The server answered " + response.status + ".");
          refused.status = response.status;
          throw refused;
        }

        return response.text().then(function (html) {
          return { html: html, url: url };
        });
      })
      .then(function (result) {
        if (!result) {
          return;
        }

        if (!swap(result.html, result.url)) {
          idle();
          return;
        }

        /* After the swap, not before: a second press while the request was in
           flight would post the same form twice. */
        idle();

        /* WHAT IS STILL ONLY IN THE FORM. admin_redirect() puts ?saved= on the
           URL it sends back, and it is the one thing that says the document on
           disk now matches what is on screen. Everything else that comes
           through here — a row added, a row moved, a save that failed
           validation — leaves the form holding something content/*.json does
           not, and admin-swap.js asks about it before following a link away. */
        global.Tech4Time.adminSwap.touched(!/[?&]saved=/.test(result.url));

        if (!showProblem(standing)) {
          restore(shot, plan);
        }
      })
      .catch(function (error) {
        idle();
        status(
          "Not sent — " + (error && error.message ? error.message : "the connection failed") +
            " " + whatNext(error),
          BAD
        );
      });
  }

  /* ----------------------------------------------------------------- wiring

     One listener on the document, in the bubble phase, so it survives every
     swap and so editor.js's own submit handler — which copies the rich text
     back into its textarea — has already run by the time this reads the form.
     Binding per form would mean rebinding after each swap and would put this
     first. */
  /* Put a count token into the field it belongs to.

     An ENHANCEMENT, and only that: the token is printed on the button, so
     with no JavaScript at all it can be read off the screen and typed. What
     this removes is the typing, not the possibility.

     It deliberately does NOT re-render the "Reads as" line beside it. Working
     out what a token comes to is certifications_fill() in lib/contract.php,
     which both halves of the project share so that the editor's preview and
     the published page cannot disagree — and a second implementation of it
     here, in another language, is exactly the disagreement that file exists to
     prevent. The line refreshes on the next redraw, from the one function that
     knows the answer. */
  function insertToken(chip) {
    var field = doc.querySelector('[name="' + chip.getAttribute("data-token-field") + '"]');
    var token = chip.getAttribute("data-token-insert");

    if (!field || !token) {
      return;
    }

    var start = typeof field.selectionStart === "number" ? field.selectionStart : field.value.length;
    var end = typeof field.selectionEnd === "number" ? field.selectionEnd : start;

    field.value = field.value.slice(0, start) + token + field.value.slice(end);

    /* Where the cursor was, plus what was just put there — so a second press
       lands after the first rather than back at the start. */
    field.focus();
    if (field.setSelectionRange) {
      field.setSelectionRange(start + token.length, start + token.length);
    }

    /* The form is watched for unsaved changes elsewhere; setting .value in
       script fires nothing on its own. */
    field.dispatchEvent(new Event("input", { bubbles: true }));
  }

  function wire() {
    var pressed = null;

    doc.addEventListener("click", function (event) {
      var chip = event.target.closest && event.target.closest("[data-token-insert]");
      if (chip) {
        insertToken(chip);
      }
    });

    doc.addEventListener("click", function (event) {
      var button = event.target.closest && event.target.closest("button, input[type=submit]");
      pressed = button && button.form ? button : null;
    });

    doc.addEventListener("submit", function (event) {
      var form = event.target;

      if (!form.matches || !form.matches("form[data-async]")) {
        return;
      }

      var submitter = event.submitter || pressed;
      pressed = null;

      event.preventDefault();
      send(form, submitter);
    });
  }

  var api = (global.Tech4Time = global.Tech4Time || {});

  api.adminForms = {
    /* Exposed so tools/test_admin_forms.py can assert the address every async
       form on every screen will actually be posted to, against the real
       function rather than a copy of its reasoning. A second implementation in
       the test is a second thing that can be wrong. */
    postUrl: postUrl,
    init: function () {
      if (!usable() || api.adminForms.wired) {
        return;
      }
      wire();
      api.adminForms.wired = true;
    },
    wired: false
  };
})(window);
