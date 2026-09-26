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
   underline, link, two lists, table, note, and the two dropdowns. A button
   for anything else would write text the renderer shows literally --
   headings beyond h6, right and justify outside the alignment menu, raw
   HTML -- so there is no button for those. Left is
   the default alignment and needs no ribbon; the menu holds left, center,
   right and justified.

   ICONS
   Letterforms for B/I/U and the list, link and alignment glyphs mirror
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
    "align-left": svg("0 0 24 24", bars([
      [3, ROWS[0], 18], [3, ROWS[1], 11], [3, ROWS[2], 18], [3, ROWS[3], 11]
    ])),
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
    "align-right": svg("0 0 24 24", bars([
      [3, ROWS[0], 18], [10, ROWS[1], 11], [3, ROWS[2], 18], [10, ROWS[3], 11]
    ])),
    "align-justify": svg("0 0 24 24", bars([
      [3, ROWS[0], 18], [3, ROWS[1], 18], [3, ROWS[2], 18], [3, ROWS[3], 18]
    ])),
    "format-clear": svg("0 0 24 24",
      '<circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="2"/>' +
      '<path d="M6.2 6.2l11.6 11.6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>'),
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
    { menu: "heading", title: "Heading level", glyph: "H", items: [
        { value: "p", glyph: "¶", label: "Paragraph" },
        { value: "h2", glyph: "H2", label: "Heading 2" },
        { value: "h3", glyph: "H3", label: "Heading 3" },
        { value: "h4", glyph: "H4", label: "Heading 4" },
        { value: "h5", glyph: "H5", label: "Heading 5" },
        { value: "h6", glyph: "H6", label: "Heading 6" }
      ] },
    { label: "B", title: "Bold", wrap: ["**", "**", "bold words"],
      className: "rte__btn--bold" },
    { label: "I", title: "Italic", wrap: ["*", "*", "emphasised words"],
      className: "rte__btn--italic" },
    { label: "U", title: "Underline", wrap: ["++", "++", "underlined words"],
      className: "rte__btn--underline" },
    { separator: true },
    { icon: "list-ul", title: "Bulleted list", lines: "- " },
    { icon: "list-ol", title: "Numbered list", lines: "1. " },
    { menu: "align", title: "Text alignment", glyphIcon: "align-left", items: [
        { value: "none", icon: "format-clear", label: "No alignment" },
        { value: "left", icon: "align-left", label: "Left" },
        { value: "center", icon: "align-center", label: "Center" },
        { value: "right", icon: "align-right", label: "Right" },
        { value: "justify", icon: "align-justify", label: "Justified" }
      ] },
    { separator: true },
    { icon: "link", title: "Insert link", link: true },
    { icon: "table", title: "Insert table", table: true },
    { icon: "note", title: "Highlight box", fence: ":::note" }
  ];

  /* Headings and alignment arrive as menus, not selects: an <option> can
     only ever be text, and this ribbon is icons. The menu applies and
     closes; it keeps no value, so it never claims the caret sits in a
     level it does not track. */
  var HEADING_PREFIX = { h2: "## ", h3: "### ", h4: "#### ", h5: "##### ",
                         h6: "###### " };

  function applyHeading(textarea, value) {
    var start = textarea.selectionStart;
    var end = textarea.selectionEnd;
    var val = textarea.value;
    if (start === null || start === undefined) {
      return;
    }
    while (start > 0 && val[start - 1] !== "\n") {
      start -= 1;
    }
    while (end < val.length && val[end - 1] !== "\n") {
      end += 1;
    }
    var marked = val.slice(start, end).split("\n").map(function (line) {
      var bare = line.replace(/^#{2,6}\s+/, "");
      if (value === "p" || bare.trim() === "") {
        return bare;
      }
      return (HEADING_PREFIX[value] || "") + bare;
    }).join("\n");
    textarea.focus();
    if (typeof textarea.setRangeText === "function") {
      textarea.setRangeText(marked, start, end, "end");
    } else {
      textarea.value = val.slice(0, start) + marked + val.slice(end);
    }
    textarea.dispatchEvent(new global.Event("input", { bubbles: true }));
  }

  function applyAlign(textarea, value) {
    var start = textarea.selectionStart;
    var end = textarea.selectionEnd;
    var val = textarea.value;
    if (start === null || start === undefined) {
      return;
    }
    var chosen = val.slice(start, end);
    if (value === "none") {
      /* Unwrap one fence pair the ribbon itself could have written; anything
         else is hand prose and stays exactly as typed. */
      var unwrapped = chosen.replace(
        /^:::(left|center|right|justify)\n([\s\S]*)\n:::$/, "$2");
      if (unwrapped === chosen) {
        return;
      }
      textarea.focus();
      if (typeof textarea.setRangeText === "function") {
        textarea.setRangeText(unwrapped, start, end, "end");
      } else {
        textarea.value = val.slice(0, start) + unwrapped + val.slice(end);
      }
    } else {
      var fence = ":::" + value;
      var trimmed = chosen.trim();
      if (trimmed === "") {
        return;
      }
      var insert = fence + "\n" + trimmed + "\n:::\n";
      var from = start + (chosen.length - chosen.replace(/^\s+/, "").length);
      var to = end - (chosen.length - chosen.replace(/\s+$/, "").length);
      textarea.focus();
      if (typeof textarea.setRangeText === "function") {
        textarea.setRangeText(insert, from, to, "end");
      } else {
        textarea.value = val.slice(0, from) + insert + val.slice(to);
      }
    }
    textarea.dispatchEvent(new global.Event("input", { bubbles: true }));
  }

  /* Dropdown menus: style (paragraph + H2-H6) and alignment. Native
     <select>s cannot carry icons in their options, and this ribbon is icons
     or it is text pretending -- so these are buttons opening menus, with
     the menu contract spelled out: one open at a time, arrows/Home/End
     travel, Enter/Space activates, Escape and outside-click close with focus
     back on the trigger, Tab leaves and closes behind it. No focus trap: a
     menu is dismissed, never modal, unlike admin-dialog.js. */
  var openMenu = null;

  function closeMenu(refocus) {
    if (!openMenu) {
      return;
    }
    var trigger = openMenu.trigger;
    openMenu.menu.setAttribute("hidden", "");
    trigger.setAttribute("aria-expanded", "false");
    openMenu = null;
    doc.removeEventListener("pointerdown", outsideClose, true);
    if (refocus !== false && trigger && trigger.focus) {
      try {
        trigger.focus();
      } catch (error) {
        /* A trigger removed mid-flight has nowhere to return to. */
      }
    }
  }

  function outsideClose(event) {
    if (openMenu && !openMenu.wrap.contains(event.target)) {
      closeMenu(true);
    }
  }

  function buildMenu(bar, textarea, tool) {
    var wrap = doc.createElement("span");
    wrap.className = "rte__menu-wrap";

    var trigger = doc.createElement("button");
    trigger.type = "button";
    trigger.className = "rte__btn";
    trigger.title = tool.title;
    trigger.setAttribute("aria-label", tool.title);
    trigger.setAttribute("aria-haspopup", "true");
    trigger.setAttribute("aria-expanded", "false");
    if (tool.glyphIcon) {
      trigger.innerHTML = ICONS[tool.glyphIcon];
    } else {
      trigger.textContent = tool.glyph;
    }
    trigger.tabIndex = -1;

    var menu = doc.createElement("span");
    menu.className = "rte__menu";
    menu.setAttribute("role", "menu");
    menu.setAttribute("hidden", "");

    tool.items.forEach(function (opt) {
      var item = doc.createElement("button");
      item.type = "button";
      item.className = "rte__menu-item";
      item.setAttribute("role", "menuitem");
      item.setAttribute("data-value", opt.value);
      item.tabIndex = -1;
      if (opt.icon) {
        item.innerHTML = ICONS[opt.icon];
      } else {
        item.textContent = opt.glyph;
      }
      var label = doc.createElement("span");
      label.className = "visually-hidden";
      label.textContent = opt.label;
      item.appendChild(label);
      item.addEventListener("click", function () {
        closeMenu(false);
        if (tool.menu === "heading") {
          applyHeading(textarea, opt.value);
        } else {
          applyAlign(textarea, opt.value);
        }
        textarea.focus();
      });
      menu.appendChild(item);
    });

    function items() {
      return Array.prototype.slice.call(menu.querySelectorAll("[role=menuitem]"));
    }

    function open() {
      if (openMenu && openMenu.menu === menu) {
        closeMenu(true);
        return;
      }
      closeMenu(false);
      menu.removeAttribute("hidden");
      trigger.setAttribute("aria-expanded", "true");
      openMenu = { wrap: wrap, menu: menu, trigger: trigger };
      doc.addEventListener("pointerdown", outsideClose, true);
      var first = items()[0];
      if (first) {
        first.focus();
      }
    }

    trigger.addEventListener("mousedown", function (event) {
      /* Same reason as the buttons: keep the caret where it is. Unlike a
         <select>, a custom menu opens on click, so prevention is safe. */
      event.preventDefault();
    });
    trigger.addEventListener("click", open);

    menu.addEventListener("keydown", function (event) {
      var list = items();
      var at = list.indexOf(doc.activeElement);
      if (event.key === "Escape") {
        event.preventDefault();
        closeMenu(true);
      } else if (event.key === "Tab") {
        closeMenu(false);
      } else if (event.key === "ArrowDown" || event.key === "ArrowRight") {
        event.preventDefault();
        list[(at + 1 + list.length) % list.length].focus();
      } else if (event.key === "ArrowUp" || event.key === "ArrowLeft") {
        event.preventDefault();
        list[(at - 1 + list.length) % list.length].focus();
      } else if (event.key === "Home") {
        event.preventDefault();
        list[0].focus();
      } else if (event.key === "End") {
        event.preventDefault();
        list[list.length - 1].focus();
      }
    });

    /* Toolbar arrows travel controls, not menu contents: moving past the
       trigger must not strand focus inside a closed menu, and an open one
       closes first. */
    wrap.addEventListener("keydown", function (event) {
      if ((event.key === "ArrowRight" || event.key === "ArrowLeft")
          && openMenu && openMenu.menu === menu) {
        closeMenu(true);
      }
    });

    wrap.appendChild(trigger);
    wrap.appendChild(menu);
    return wrap;
  }

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
      if (tool.menu) {
        bar.appendChild(buildMenu(bar, textarea, tool));
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
      button.tabIndex = -1;
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

    /* One tab stop for the whole toolbar; arrow keys travel its top-level
       controls -- plain buttons and menu triggers, never the items inside
       an open menu (those have their own travel, and the wrap handler above
       yields to them). The first control takes the stop; everything else is
       reached by arrows. */
    var first = bar.querySelector(":scope > button, :scope > .rte__menu-wrap > button");
    if (first) {
      first.tabIndex = 0;
    }
    bar.addEventListener("keydown", function (event) {
      var keys = { ArrowRight: 1, ArrowLeft: -1, Home: "first", End: "last" };
      if (!(event.key in keys)) {
        return;
      }
      /* Inside an open menu, arrows belong to the menu items, not to the
         toolbar travel below. */
      if (event.target && event.target.closest
          && event.target.closest(".rte__menu")) {
        return;
      }
      event.preventDefault();
      var items = Array.prototype.slice.call(
        bar.querySelectorAll(":scope > button, :scope > .rte__menu-wrap > button"));
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
