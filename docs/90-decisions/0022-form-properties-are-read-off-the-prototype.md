# 0022 — A form's properties are read off the prototype, never off the form

**Status:** accepted · **Applies to:** both

## Decision

No script reads `action`, `method`, `enctype`, `target`, `elements`, `submit`, `requestSubmit` or
`reset` off a `<form>` element. Each is read through `HTMLFormElement.prototype` instead —
`Object.getOwnPropertyDescriptor(HTMLFormElement.prototype, 'action').get.call(form)`, and the
matching form for the others.

`tools/check_form_dom.py` refuses those eight outright, and refuses every *other* form property a
control in this repository is actually named after — a set it re-derives from the markup on each
run rather than keeping a list. It runs in both halves and in both CI workflows.

## Context

`HTMLFormElement` is declared `[LegacyOverrideBuiltIns]` in the HTML specification. That means a
control's **name wins over the interface's own property of the same name**, with no warning, no
error and no way to tell by looking:

```html
<form action="?s=careers">
  <input type="hidden" name="action" value="save">
</form>
```

```js
form.action              // the INPUT element — not a URL
String(form.action)      // "[object HTMLInputElement]"
fetch(form.action, …)    // POST /[object%20HTMLInputElement]  →  404
```

Ordinary submission never consults the property: the browser submits from the *attribute*. So a
form can be correct for as long as it is submitted normally and break on the day a script starts
posting it — years later, in a commit that touched neither the form nor the field.

**That is what happened.** `sections/careers.php` has carried
`<input type="hidden" name="action">` in every one of its five forms since 2026-08-27, because the
section reads `$_POST['action']` to tell save from delete from move from settings. On 2026-08-28
`public/assets/js/admin-forms.js` began posting the editors with `fetch(form.action || location.href)`. From that
day every button on the careers screen — save a post, publish, unpublish, reorder, delete, save the
CV link — posted to `/[object%20HTMLInputElement]`, and both hosts answered 404. It was reported
from production as four stacked toasts, one per press, and reproduced identically against the local
server.

Careers was the only screen affected, because it is the only section with a control named after a
form property that a script reads.

## Why the prototype, rather than renaming the field

Renaming `action` to something the DOM does not use would fix this screen and teach nothing. The
mechanism is a property of every form in both repositories: `name` is already a control on the
public contact page — `tech4time-website-frontend/pages/contact/index.php` — and on
`public/setup.php`, `id` and `title` are already controls on the careers screen, and `method` and `reset` are one ordinary field name away from breaking the contact form's
submit and its clear-after-send. Reading through the prototype is immune to all of it, costs one
function, and needs nobody to remember anything.

The getter also *is* the specification's behaviour — the attribute resolved against the document's
base URL, and the document's own URL when there is no attribute — so nothing is reimplemented and
nothing can drift from what a plain `<form>` would have done.

## Why a check, and why this one is shaped as it is

Every existing suite passed throughout. `tools/test_admin_forms.py` ran 215 checks over these
screens at the time and `tools/check_admin_a11y.py` ran 1845, and neither could see it: both drive the markup,
and the markup was correct. `check_secrets.py` looks for inline handlers, `check_css.py` for
stylesheets. Nothing read a property off a form, because nothing had reason to.

So the check does two things rather than one. It refuses the eight members that decide where a
request goes, how it is sent and whether the typing survives — unconditionally, because "nothing is
named that yet" is a fact about the markup and not about the code that reads it. And it re-derives
the rest of the rule from the markup, so a field added tomorrow that shadows something a script
already reads fails tomorrow rather than on the day somebody presses the button.

It cannot see a form it cannot recognise as one — a form reached as `event.target`, or passed in
under an unhelpful name. So `tools/test_admin_forms.py` also asks the module, on every admin
screen, for the address it would post each form to and insists it is one this page's own path
answers; and it presses a real button on the careers screen and asserts the answer was not an
error.

## Consequences

- Two lines of `public/assets/js/admin-forms.js` and three of `tech4time-website-frontend/assets/js/forms.js` go through a helper. Both helpers carry the
  reasoning in full, because it is not deducible from the code.
- `public/assets/js/admin-forms.js` exposes `postUrl` on `window.Tech4Time.adminForms` so the browser suite can
  assert against the function that runs rather than a copy of its reasoning.
- Control names that shadow a form property but that no script reads are **not** faults.
  `$_POST['action']` is a perfectly good thing to send. `check_form_dom.py` lists them as a standing
  notice, so somebody about to write `form.something` can see which names are already taken.
- Neither half's behaviour with JavaScript off changes at all. It never did: with no script, the
  browser submits from the attribute and the bug does not exist.
