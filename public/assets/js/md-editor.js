/* ==========================================================================
   Tech4TIME — md-editor.js
   Ribbon buttons for the Markdown fields in /admin/ (the legal documents).

   PROGRESSIVE ENHANCEMENT, AND MEANING IT
   Every field ships as an ordinary <textarea> holding Markdown source, and
   the form saves it verbatim. This adds buttons that insert syntax around
   the selection; if the file never loads, the syntax is still typed by hand
   and still saves. Nothing depends on this running, and there is no surface
   to keep in sync -- the textarea IS the field, unlike editor.js, whose
   contenteditable writes HTML into a hidden one.

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

   PREVIEW IS NOT HERE
   Rendering Markdown is PHP's job (lib/markdown.php, shared with the
   frontend), and a second renderer in this file would be a second
   implementation of a security boundary to keep identical for ever. Preview
   posts the form and gets back the same HTML the page will print.
   ========================================================================== */

(function (global) {
  "use strict";

  var doc = global.document;

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

  var TOOLS = [
    { label: "B", title: "Bold", wrap: ["**", "**", "bold words"],
      className: "rte__btn--bold" },
    { label: "I", title: "Italic", wrap: ["*", "*", "emphasised words"],
      className: "rte__btn--italic" },
    { label: "U", title: "Underline", wrap: ["++", "++", "underlined words"],
      className: "rte__btn--underline" },
    { separator: true },
    { label: "•", title: "Bulleted list", lines: "- ",
      className: "rte__btn--list" },
    { label: "1.", title: "Numbered list", lines: "1. ",
      className: "rte__btn--list" },
    { label: "Link", title: "Insert link", link: true },
    { separator: true },
    { label: "Table", title: "Insert table", table: true },
    { label: "Note", title: "Highlight box", fence: ":::note" },
    { label: "Centre", title: "Centre block", fence: ":::center" }
  ];

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
      global.alert("Select the words you want to link first.");
      return;
    }
    var url = global.prompt("Link address", "https://");
    if (!url) {
      return;
    }
    url = url.trim();
    if (!/^(https?:\/\/|mailto:|\/)/i.test(url)) {
      global.alert(
        "Links must start with https://, mailto: or / — anything else is " +
        "shown as plain text when the page renders."
      );
      return;
    }
    surround(textarea, "[", "](" + url + ")", selection);
  }

  function insertTable(textarea) {
    var input = global.prompt("How many columns? (2 to 6)", "2");
    if (!input) {
      return;
    }
    var cols = parseInt(input, 10);
    if (isNaN(cols) || cols < 2 || cols > 6) {
      global.alert("Columns must be a number from 2 to 6.");
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
  }

  function build(textarea) {
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
      button.textContent = tool.label;
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

    textarea.parentNode.insertBefore(bar, textarea);
    textarea.classList.add("md__source");
  }

  var api = (global.Tech4Time = global.Tech4Time || {});

  api.mdEditor = {
    init: function () {
      /* :not(.md__source) because admin-forms.js calls every init again
         after it swaps a screen in, and a textarea that survived already
         has its toolbar above it. editor.js guards the same way. */
      var fields = doc.querySelectorAll("textarea[data-md]:not(.md__source)");
      Array.prototype.forEach.call(fields, build);
    }
  };
})(window);
