/* ==========================================================================
   Tech4TIME — md-editor.js
   Ribbon buttons for the Markdown fields in /admin/ (the legal documents).

   PROGRESSIVE ENHANCEMENT, AND MEANING IT
   Every field ships as an ordinary <textarea> holding Markdown source, and
   the form saves it verbatim. This wraps field and ribbon in one bordered
   box and inserts syntax around the selection; if the file never loads, the
   syntax is still typed by hand and still saves. Nothing depends on this
   running, and there is no surface to keep in sync -- the textarea IS the
   field, unlike editor.js, whose contenteditable writes HTML into a hidden
   one.

   WHY NOT EXTEND editor.js
   That module owns an HTML surface: every command writes tags, its pressed
   states read tags back, and its sync posts innerHTML. Markdown wants the
   opposite -- plaintext in, syntax markers out, no states at all. Sharing
   the file would mean a mode flag on every method; a second small module
   with no modes is cheaper to read and cannot regress the HTML editors.

   THE TOOLBAR IS CLOSED
   One button per construct the dialect knows (see
   tech4time-website-frontend/plans/legal-markdown-syntax.md): bold, italic,
   underline, link, two lists, table, note, centre. A button for anything
   else would write text the renderer shows literally -- alignment beyond
   centre, headings, raw HTML -- so there is no button for those. Left is
   the default alignment and needs no ribbon; right and justify have no
   syntax and therefore no button.

   ICONS
   Letterforms for B/I/U and the list, link and centre glyphs mirror
   editor.js's ICONS, which stays the master: same 24px grid, same bar
   weights, same Font Awesome link path. Table and note are drawn here in
   that style because editor.js has no such buttons. If a shared glyph ever
   drifts, the careers editor shows it first -- these pages are looked at
   daily, the legal ones are not.

   NO BROWSER DIALOGS
   Link addresses, table widths and validation messages go through the
   admin's own dialog (admin-dialog.js), never window.prompt() or
   window.alert(). A native box leaves the page for a browser window, and
   whatever dismissed it dismissed the flow with it; the in-app box stays
   under the same focus trap and backdrop as every confirmation here.
   Without admin-dialog.js the calls fall back to prompt()/alert(), which is
   the documented bargain of that module, not a second path maintained here.

   PREVIEW IS NOT HERE
   Rendering Markdown is PHP's job (lib/markdown.php, shared with the
   frontend), and a second renderer in this file would be a second
   implementation of a security boundary to keep identical for ever. Preview
   posts the form and gets back the same HTML the page will print.
   ========================================================================== */

(function (global) {
  "use strict";

  var doc = global.document;

  /* Toolbar icons. Letterforms for B/I/U (a bold B says more than any glyph),
     bars for lists and alignment, the Font Awesome link path shared with
     editor.js, and table/note drawn in the same 24px vocabulary. */
  function svg(viewBox, body) {
    return '<svg class="rte__icon" viewBox="' + viewBox + '" aria-hidden="true" ' +
           'focusable="false">' + body + '</svg>';
  }

  function bars(rows) {
    return rows.map(function (row) {
      return '<rect x="' + row[0] + '" y="' + row[1] + '" width="' + row[2] +
             '" height="2" rx="1"/>';
    }).join("");
  }

  var ROWS = [4, 8.5, 13, 17.5];

  var ICONS = {
    "list-ul": svg("0 0 24 24",
      '<circle cx="4.5" cy="6" r="1.75"/><circle cx="4.5" cy="12" r="1.75"/>' +
      '<circle cx="4.5" cy="18" r="1.75"/>' +
      bars([[9, 5, 12], [9, 11, 12], [9, 17, 12]])),
    "list-ol": svg("0 0 24 24",
      '<text x="1" y="8.5" font-size="8" font-weight="700">1</text>' +
      '<text x="1" y="14.5" font-size="8" font-weight="700">2</text>' +
      '<text x="1" y="20.5" font-size="8" font-weight="700">3</text>' +
      bars([[10, 5, 11], [10, 11, 11], [10, 17, 11]])),
    "link": svg("0 0 640 512", '<path d="M579.8 267.7c56.5-56.5 56.5-148 0-204.5c-50-50-128.8-56.5-186.3-15.4l-1.6 1.1c-14.4 10.3-17.7 30.3-7.4 44.6s30.3 17.7 44.6 7.4l1.6-1.1c32.1-22.9 76-19.3 103.8 8.6c31.5 31.5 31.5 82.5 0 114L422.3 334.8c-31.5 31.5-82.5 31.5-114 0c-27.9-27.9-31.5-71.8-8.6-103.8l1.1-1.6c10.3-14.4 6.9-34.4-7.4-44.6s-34.4-6.9-44.6 7.4l-1.1 1.6C206.5 251.2 213 330 263 380c56.5 56.5 148 56.5 204.5 0L579.8 267.7zM60.2 244.3c-56.5 56.5-56.5 148 0 204.5c50 50 128.8 56.5 186.3 15.4l1.6-1.1c14.4 10.3 17.7 30.3 7.4 44.6s-30.3 17.7-44.6 7.4l-1.6 1.1c-32.1 22.9-76 19.3-103.8-8.6C74 372 74 321 105.5 289.5L217.7 177.2c31.5 31.5 82.5 31.5 114 0c27.9 27.9 31.5 71.8 8.6 103.9l-1.1 1.6c-10.3 14.4-6.9 34.4 7.4 44.6s34.4 6.9 44.6-7.4l1.1-1.6C433.5 260.8 427 182 377 132c-56.5-56.5-148-56.5-204.5 0L60.2 244.3z"/>'),
    "align-center": svg("0 0 24 24", bars([
      [3, ROWS[0], 18], [6.5, ROWS[1], 11], [3, ROWS[2], 18], [6.5, ROWS[3], 11]
    ])),
    "table": svg("0 0 24 24",
      '<rect x="3" y="4" width="18" height="16" rx="1.5" fill="none" stroke="currentColor" stroke-width="2"/>' +
      '<rect x="3" y="10.5" width="18" height="2"/>' +
      '<rect x="3" y="15" width="18" height="2"/>' +
      '<rect x="11" y="4" width="2" height="16"/>'),
    "note": svg("0 0 24 24",
      '<rect x="3" y="3" width="3" height="18" rx="1"/>' +
      bars([[8, 5, 13], [8, 11, 13], [8, 17, 9]]))
  };

  /* Wraps the selection, or inserts a template when nothing is selected. */
  function surround(textarea, before, after, placeholder) {
    var start = textarea.selectionStart;
    var end = textarea.selectionEnd;
    var value = textarea.value;

    /* A field never focused has no selection at all: append at the end
       rather than formatting the wrong place. */
    if (start === null || start === undefined || end === null || end === undefined) {
      start = value.length;
      end = value.length;
    }

    var chosen = value.slice(start, end) || placeholder;
    var insert = before + chosen + after;
    textarea.focus();
    if (typeof textarea.setRangeText === "function") {
      textarea.setRangeText(insert, start, end, "end");
    } else {
      textarea.value = value.slice(0, start) + insert + value.slice(end);
    }
    textarea.dispatchEvent(new global.Event("input", { bubbles: true }));
  }

  /* A GFM skeleton: header, delimiter, one body row. The author fills the
     cells and adds rows by copying a line -- a grid picker that asked for
     dimensions would be guessing at content it cannot see. Strict tables
     refuse ragged rows, so the skeleton is square by construction. */
  function tableSkeleton(cols) {
    var cells = [];
    var rule = [];
    var i;
    for (i = 0; i < cols; i++) {
      cells.push("   ");
      rule.push("---");
    }
    return "| " + cells.join(" | ") + " |\n"
         + "| " + rule.join(" | ") + " |\n"
         + "| " + cells.join(" | ") + " |\n";
  }

  function blockWrap(textarea, fence) {
    var start = textarea.selectionStart;
    var end = textarea.selectionEnd;
    var value = textarea.value;
    if (start === null || start === undefined) {
      start = value.length;
      end = value.length;
    }
    /* Fences stand on their own lines: split the selection off from
       neighbouring prose so the block parses as a block. */
    var chosen = (value.slice(start, end) || "highlighted words").trim();
    var insert = fence + "\n" + chosen + "\n:::\n";
    textarea.focus();
    if (typeof textarea.setRangeText === "function") {
      textarea.setRangeText(insert, start, end, "end");
    } else {
      textarea.value = value.slice(0, start) + insert + value.slice(end);
    }
    textarea.dispatchEvent(new global.Event("input", { bubbles: true }));
  }

  function dialog() {
    return global.Tech4Time && global.Tech4Time.adminDialog;
  }

  function prefixLines(textarea, marker) {
    var start = textarea.selectionStart;
    var end = textarea.selectionEnd;
    var value = textarea.value;
    if (start === null || start === undefined) {
      start = value.length;
      end = value.length;
    }
    /* Extend to whole lines: a list marker mid-line would split a paragraph
       into a list plus a fragment, and the fragment is the part nobody sees
       coming. */
    while (start > 0 && value[start - 1] !== "\n") {
      start -= 1;
    }
    while (end < value.length && value[end - 1] !== "\n") {
      end += 1;
    }
    var chosen = value.slice(start, end) || "first item";
    var marked = chosen.split("\n").map(function (line) {
      return line.trim() === "" ? line : marker + line;
    }).join("\n");
    textarea.focus();
    if (typeof textarea.setRangeText === "function") {
      textarea.setRangeText(marked, start, end, "end");
    } else {
      textarea.value = value.slice(0, start) + marked + value.slice(end);
    }
    textarea.dispatchEvent(new global.Event("input", { bubbles: true }));
  }

  function insertLink(textarea) {
    var selection = "";
    if (typeof textarea.selectionStart === "number") {
      selection = textarea.value.slice(textarea.selectionStart, textarea.selectionEnd);
    }
    if (!selection) {
      notify("Select the words you want to link first.");
      return;
    }
    askInput({
      title: "Insert link",
      message: "Link address — https://, mailto: or /",
      label: "Address",
      value: "https://",
      confirm: "Insert"
    }).then(function (url) {
      if (!url) {
        return;
      }
      url = url.trim();
      if (!/^(https?:\/\/|mailto:|\/)/i.test(url)) {
        notify("Links must start with https://, mailto: or / — anything else "
             + "is shown as plain text when the page renders.");
        return;
      }
      surround(textarea, "[", "](" + url + ")", selection);
    });
  }

  function insertTable(textarea) {
    askInput({
      title: "Insert table",
      message: "A square skeleton: header, rule, one body row. Fill the cells, add rows by copying a line.",
      label: "Columns (2 to 6)",
      value: "2",
      confirm: "Insert"
    }).then(function (answer) {
      if (answer === null || answer === undefined) {
        return;
      }
      var cols = parseInt(answer, 10);
      if (isNaN(cols) || cols < 2 || cols > 6) {
        notify("Columns must be a number from 2 to 6.");
        return;
      }
      var start = textarea.selectionStart;
      var value = textarea.value;
      if (start === null || start === undefined) {
        start = value.length;
      }
      /* Own lines, like fences: a table shares a line with prose in neither
         direction, because the dialect reads a table as whole lines. */
      var insert = "\n" + tableSkeleton(cols);
      textarea.focus();
      if (typeof textarea.setRangeText === "function") {
        textarea.setRangeText(insert, start, start, "end");
      } else {
        textarea.value = value.slice(0, start) + insert + value.slice(start);
      }
      textarea.dispatchEvent(new global.Event("input", { bubbles: true }));
    });
  }

  /* Through the admin's own dialog where it exists, window.prompt() where it
     does not -- which is admin-dialog.js's documented fallback, not a second
     path maintained here. Both answer a promise for the string or null. */
  function askInput(options) {
    var api = dialog();
    if (api && typeof api.askInput === "function") {
      return api.askInput(options);
    }
    var answer = global.prompt(
      (options.title ? options.title + "\n\n" : "") + (options.message || ""),
      options.value || ""
    );
    return {
      then: function (fn) { fn(answer); return this; }
    };
  }

  /* Likewise for window.alert(). */
  function notify(message) {
    var api = dialog();
    if (api && typeof api.notify === "function") {
      return api.notify(message);
    }
    global.alert(message);
  }

  var TOOLS = [
    { label: "B", title: "Bold", wrap: ["**", "**", "bold words"],
      className: "rte__btn--bold" },
    { label: "I", title: "Italic", wrap: ["*", "*", "emphasised words"],
      className: "rte__btn--italic" },
    { label: "U", title: "Underline", wrap: ["++", "++", "underlined words"],
      className: "rte__btn--underline" },
    { separator: true },
    { icon: "list-ul", title: "Bulleted list", lines: "- " },
    { icon: "list-ol", title: "Numbered list", lines: "1. " },
    { icon: "link", title: "Insert link", link: true },
    { separator: true },
    { icon: "table", title: "Insert table", table: true },
    { icon: "note", title: "Highlight box", fence: ":::note" },
    { icon: "align-center", title: "Centre block", fence: ":::center" }
  ];

  function build(textarea) {
    /* Field and ribbon in one bordered box, the way the careers surface
       fuses toolbar and text: a ribbon floating above the field reads as a
       control for something else. */
    var box = doc.createElement("div");
    box.className = "md";

    var bar = doc.createElement("div");
    bar.className = "rte__toolbar";
    bar.setAttribute("role", "toolbar");
    bar.setAttribute("aria-label", "Markdown formatting");

    TOOLS.forEach(function (tool, index) {
      if (tool.separator) {
        var hr = doc.createElement("span");
        hr.className = "rte__separator";
        hr.setAttribute("aria-hidden", "true");
        bar.appendChild(hr);
        return;
      }
      var button = doc.createElement("button");
      button.type = "button";
      button.className = "rte__btn" + (tool.className ? " " + tool.className : "");
      if (tool.icon) {
        button.innerHTML = ICONS[tool.icon];
      } else {
        button.textContent = tool.label;
      }
      button.title = tool.title;
      button.setAttribute("aria-label", tool.title);
      button.tabIndex = index === 0 ? 0 : -1;
      button.addEventListener("mousedown", function (event) {
        /* Keep the caret in the textarea: focusing the button would move
           the selection before the insertion could use it. */
        event.preventDefault();
      });
      button.addEventListener("click", function () {
        if (tool.wrap) {
          surround(textarea, tool.wrap[0], tool.wrap[1], tool.wrap[2]);
        } else if (tool.lines) {
          prefixLines(textarea, tool.lines);
        } else if (tool.link) {
          insertLink(textarea);
        } else if (tool.table) {
          insertTable(textarea);
        } else if (tool.fence) {
          blockWrap(textarea, tool.fence);
        }
      });
      bar.appendChild(button);
    });

    /* Arrow keys travel the toolbar; it is one tab stop, like editor.js. */
    bar.addEventListener("keydown", function (event) {
      var keys = { ArrowRight: 1, ArrowLeft: -1, Home: "first", End: "last" };
      if (!(event.key in keys)) {
        return;
      }
      event.preventDefault();
      var items = Array.prototype.slice.call(bar.querySelectorAll("button"));
      var current = items.indexOf(doc.activeElement);
      if (current < 0) {
        current = 0;
      }
      var next;
      if (keys[event.key] === "first") {
        next = 0;
      } else if (keys[event.key] === "last") {
        next = items.length - 1;
      } else {
        next = (current + keys[event.key] + items.length) % items.length;
      }
      items.forEach(function (el) { el.tabIndex = -1; });
      items[next].tabIndex = 0;
      items[next].focus();
    });

    /* The box takes the textarea's place; the textarea moves inside it under
       the bar, borderless, so ribbon and field read as one control. */
    box.appendChild(bar);
    textarea.parentNode.insertBefore(box, textarea);
    box.appendChild(textarea);
    textarea.classList.add("md__source");
  }

  var api = (global.Tech4Time = global.Tech4Time || {});

  api.mdEditor = {
    init: function () {
      /* :not(.md__source) because admin-forms.js calls every init again
         after it swaps a screen in, and a textarea that survived the swap
         already has its box around it. editor.js guards the same way. */
      var fields = doc.querySelectorAll("textarea[data-md]:not(.md__source)");
      Array.prototype.forEach.call(fields, build);
    }
  };
})(window);
