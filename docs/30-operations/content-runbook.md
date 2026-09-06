# Content runbook

**Applies to:** backend

Day-to-day content work. Written for whoever maintains the site, not for a developer.

Everything here happens at **https://admin.tech4time.bd/** — no file editing, no deploy.

---

## Signing in

1. Go to the admin
2. Your username and password
3. Six digits from your authenticator app

Sessions last one hour of inactivity, twelve hours at most.

Trouble getting in: [secrets-recovery.md](secrets-recovery.md).

---

## Finding your way around

| Where | What is there |
|---|---|
| Down the left | the pages you can edit, in the order they appear on the site: Overview, Home, About Us, Services, Company Profile, Careers, Contact, Resource Certifications, Branding & Advertisement, Privacy Policy, and **SEO Management** last |
| The round `‹` at the top of that column | narrows it to icons. It remembers, so it stays that way |
| Your name at the **bottom** of that column | your account, and **Sign out**. **Your account is not in the list above** — it is about you rather than about a page, so it lives here |
| **Down the right**, under *On this page* | **every section of the page you are editing** — click a name to jump straight to it. On a narrower screen it sits above the form instead |
| Beside the page name | two round buttons: **view the page** (the eye) and **open the site** (the link). Both open in a new tab |
| Top right | **Save**, **Discard**, and the light/dark switch |
| Under the page name | which file you are editing, and where the changes go |

**Save is always in the same place**, whatever you have scrolled to. There is no button at the
bottom to hunt for.

### Nothing jumps any more

Adding a row, removing one, moving one up or down, and saving all happen **where you are standing**.
The page does not reload and does not scroll back to the top, so arranging fifty logos is fifty
clicks in one place rather than fifty round trips. Move a row and the buttons stay under your
pointer — press again and the same row moves again.

If your browser has JavaScript switched off, everything still works; it simply reloads the page each
time, the way it used to.

---

## Job posts

**Admin → Careers**

### Posting a job

1. **Add a job post**
2. Fill in the title, location, type and description
3. Set the application link — usually a Google Form. Job applications do not post to this site at
   all; each role links out to its own form
4. **Save**

It is live immediately. `/pages/careers/` renders straight from what you saved.

### Editing, reordering, removing

Edit any post in place and save. Drag to reorder — the order here is the order on the page. Removing
a post takes it off the site; there is no archive, so if it might come back, keep the text
somewhere.

### Formatting

The description accepts basic formatting: bold, italic, lists, links, and headings.

- **Alignment is a choice from a list**, not free-form styling. The site's security policy blocks
  inline styles, so a pasted style would look right in the editor and do nothing on the page.
- **Pasting from Word or Google Docs** is safe — anything the editor does not recognise is discarded
  rather than carried through.

### The CV link

One link for the whole page, for speculative applications. Admin → Careers, at the top.

---

## Contact details

**Admin → Contact**

Offices, phone numbers, email addresses, the page's headings, and the enquiry form's copy.

### Showing and hiding

Two switches, and they are not the same instruction:

| Switch | Where | What it does |
|---|---|---|
| **the section's** | at the top of *Reach us directly* or *Our offices* | takes the whole band off the page, keeping everything in it |
| **a row's** | at the foot of each row or office card | takes that one entry off, keeping the band |

Hidden means hidden: a hidden band or row is gone from the page **and** from the structured data
the page gives Google. Nothing is deleted — a hidden entry is still in the editor, marked
**Hidden**, and switching it back on puts it straight back.

The banner and the enquiry form have no switch on purpose. A contact page with no way to make
contact is not a page anybody meant to publish.

### Flags

Each office can have a flag, and there are two ways to give it one:

- **Upload a picture.** The way to add any country. It is re-encoded here — which is what strips the
  location and camera details out of a photograph — reduced to fit, given a WebP version, and sent
  to the live site straight away.
- **Pick one of the three built into the site.** Bangladesh, Belgium and Malaysia. This list cannot
  grow without a developer, which is why the upload exists.

An uploaded flag always wins. Remove it to fall back to the bundled list.

### The footer needs a developer

> **Changing a phone number here does not change the footer.**

The contact details repeated at the bottom of every page are part of each page's markup, not
content, so the editor cannot reach them. The admin shows a banner when the two disagree.

Closing the gap needs someone with the repository:

```bash
# 1. the server's copy is the real one — download it first
scp user@tech4time.bd:~/public_html/content/contact.json content/contact.json

# 2. push the details into every page
python3 tools/sync_site_contact.py
python3 tools/check_shared_markup.py

# 3. deploy the pages — and NOT content/
```

So: change it in the admin, then ask for a deploy. The contact page itself is correct immediately;
only the footers lag.

---

## The company profile

`https://admin.tech4time.bd/?s=company` — the milestones, the figures, the client logos, the
photographs, the technology list, the principles, and every heading and paragraph around them.

It is one long form, like the contact page. **Nothing reaches the site until you press Save**, and
the buttons that add, remove and reorder deliberately do not save — so you can move three things
and change a heading and then save once.

### Hiding is not deleting

Every entry, and every whole section, has a **Shown / Hidden** control. A hidden thing keeps its
place, its words and its picture, and simply does not appear on the site. That is what you want
when a client asks to be off the page for a quarter; deleting them means typing it all back.

Hiding a *section* hides everything in it. "Our Background" contains the figures, the clients and
the photographs — hide it and all three go, whatever their own switches say.

### Adding a picture

Choose a file on any row that has one. JPEG, PNG or WebP, up to 5 MB.

**It is not stored as you sent it.** The server decodes the picture and writes a new one from the
pixels. That is what removes the location and camera details a photograph carries — a phone photo
posted as-is tells everybody where it was taken. It is also reduced to 1600 pixels on its longest
side, given a WebP version for browsers that prefer one, and sent to the live site straight away.

An SVG cannot be used. It is a document rather than a picture and can carry code, so it is refused
whatever it is named.

**The description is not optional.** For a logo it is the client's name; for a photograph, say what
is happening in it. It is what somebody using a screen reader hears instead of the picture, and it
is what a search engine reads.

### The order matters more than it looks

The **milestones** alternate left and right down the page, so inserting one moves every entry after
it to the other side. The **technology** logos are placed on a rotating sphere by their position,
so reordering or adding one redistributes all of them. Neither is a problem — just expect the page
to look rearranged rather than only changed.

### Figures must start with a number

`100+` and `99%` work. `Over 100` does not, and the editor will say so: the count-up animation reads
the number off the front, and a figure it cannot read just sits there.

### Stored pictures

At the bottom is a count of the pictures held and how many no rows are using. Replacing a picture
leaves the old one behind, which is normal. Nothing is ever deleted on its own — press the button
when you want the unused ones gone, and only when you have saved.

## The home page

`https://admin.tech4time.bd/?s=home` — the hero and its badges, tags and terminal; the technical
domains; the six service cards; the Get to Know Us cards; and the closing band.

One long form again, and the same rules: **nothing reaches the site until you press Save**, the
add / remove / reorder buttons deliberately do not save, and every row and every section has a
**Shown / Hidden** control. The hero itself is the one thing with no Hidden switch — a front page
with no heading is a broken page, not a page with a section switched off.

### The highlighted word in the headline

The headline is one field and the phrase drawn in the accent colour is another. Type the phrase
exactly as it appears in the headline, capitals included. Change the headline and forget the
phrase, and saving will refuse and say so — because a phrase that is not in the headline highlights
nothing, and the page would look merely a little plainer rather than wrong.

Leave the phrase empty for a headline in one colour.

### The terminal is a picture of a shell

Nothing in it runs. Each line is either **a typed command**, which the browser types out character
by character behind a prompt, or **output**, which arrives whole. Output can be **Plain**,
**Success** or **Alert** — that only picks the colour; the tick and the exclamation mark at the
start of those lines are part of the text, so you can write something else.

The blinking cursor at the end is not a line and does not appear in the list. It is added
automatically after the last one, and follows the last command's prompt.

**The one-line description matters.** The console is hidden from screen readers, because reading
shell output line by line is noise — that sentence is read instead. If you change what the console
shows, change the sentence.

### The service cards are also what a search engine is told

The six cards and the six services in the page's structured data are the same six. Add a card and
the search engine is told about it; hide one and it is not. Two fields under the heading — *What a
search engine calls this list* and *how it describes it* — are the only things on this screen that
never appear on the page.

### Pictures on the Get to Know Us cards

Each card has **two** upload slots, one per colour mode, and the second is almost always empty.
The artwork is line drawings that sit on a light plate in both modes by design, so one picture is
the normal case. Upload a dark one only if you have artwork made for a dark page; it takes that
card off the light plate.

## The about page

`https://admin.tech4time.bd/?s=about` — the banner, the five image-and-prose sections, the
specialities slideshow, the why-us cards and the closing band.

One long form again, and the same rules: **nothing reaches the site until you press Save**, the
add / remove / reorder buttons deliberately do not save, and every row and every section has a
**Shown / Hidden** control.

### The sections are a list, not five fixed slots

The Company, Our Goal, Our Mission, Our Vision and Our Ambition are five entries in one list. You
can add a sixth, move Vision above Mission, or hide Ambition for a while — none of it needs a
developer.

Two things are chosen per section rather than fixed:

**Every section has two upload slots**, one per colour mode, whichever picture it uses. The second
is almost always empty and that is normal: the illustrations sit on a white plate in both light and
dark mode by design, so one picture is the usual case. Upload a dark one only if you have artwork
made for a dark page — it takes that section off the white plate. Same control as the Get to Know Us
cards on the home page.

**Picture** — *A photograph*, or *The Tech4TIME logo lockup*. The lockup swaps itself for light and
dark mode, and a section using it gets **two upload slots**, one per mode. Leave them empty and the
logo that ships with the site is used. Upload only the light one and it is used in both modes —
that is on purpose, because the alternative is the old logo showing beside the new one; upload a
dark version too if the light one does not read on a dark background.

**This replaces the logo in that section only.** The one in the header, the footer, the browser tab
and on a shared link is part of the site itself and still needs a developer.

A picture is kept rather than thrown away when you switch a section to the lockup, so switching
back does not lose it. Only "The Company" uses the lockup today.

**Which side** — the picture sits left or right. On a narrow screen it is always on top, whatever
you choose.

The light and shaded backgrounds alternate down the page **by position**. Reordering keeps the
stripe, so a section you move changes its background — expect the page to look rearranged rather
than only changed.

### The text of a section

The prose box is the same rich-text control the other editors use. Write one or two paragraphs;
each one fades in on its own as the reader reaches it. That is automatic — there is nothing to
switch on.

### The specialities and the why-us cards

Both are icon + title + text, and the icon list is fixed: it is drawn from a set of symbols that
ship with the site, so only those can be offered. The preview beside the picker shows what is
drawn, and updates when you save.

The **specialities** are a slideshow. *Time on each card* is in milliseconds — 10000 is ten
seconds — and it never advances on its own for somebody who has asked for reduced motion. Without
JavaScript every card is on screen at once, so the order is the reading order either way.

### Pictures, and the stored count

Exactly as on the company profile: JPEG, PNG or WebP up to 5 MB, re-encoded on arrival so the
camera and location details a photograph carries are removed, and the description is not optional.
The stored-pictures count at the bottom is shared across every editor, which is why nothing is ever
swept automatically.

## SEO Management

**Admin → SEO Management**

Everything a search engine is told about the site. It is one section over several screens, because
one screen holding every page would be enormous.

**The first screen is a list.** One row per page — including every service, so a service added in
the Services editor appears here by itself — showing its title, whether the title and description
are a good length, and whether the page is indexed. Click a row to edit that page and nothing else.

Two more rows at the bottom open the site-wide screens: **the whole site**, and **crawling and
installing**.

### What you can change for one page

| | |
|---|---|
| **Browser tab title** | what the tab says, and the blue heading in a Google result. Keep it under 65 characters or it is cut off |
| **Search description** | the paragraph under that heading. Between 50 and 165; **150–160 reads best**, and the box tells you where you are |
| **Keywords** | words this page is about, separated by commas. Worth knowing before you spend time on it: **Google has ignored this tag since 2009** and Bing treats a stuffed one as spam. A few true words help a handful of smaller engines and the site's own search; a long list helps nobody. Left empty, nothing is sent |
| **Title on a shared link** | what Facebook, LinkedIn and WhatsApp show. Usually the title without the brand suffix |
| **Share picture** | leave it empty and the page uses the site's own card, which is what every page does today |
| **Name in a breadcrumb trail** | only a search engine sees this. There is no breadcrumb on the site itself |
| **In search results** | *Indexed* or *Not indexed* — read the warning below before changing it |
| **How often it changes / Priority** | hints for the sitemap. They matter little; sensible values are already set |

**The same words twice is refused.** Two pages cannot share a title or a description — a search
engine has to be able to tell them apart, and if it cannot it usually shows neither.

The **web address** of a page is not editable here, and not anywhere. One page has one address, and
that is what tells Google which page is the real one.

### "Not indexed" is slow to undo, and that is the point

Setting a page to **Not indexed** asks Google to drop it from search and removes it from the
sitemap. The editor asks you to confirm and names the page before it does anything.

**Undoing it is not instant.** Switching back to *Indexed* only means Google may list it again the
next time it crawls the page — on Google's schedule, not yours, and that can take weeks. Nothing on
this end can make it faster.

The list screen carries a standing note showing every page currently set to *Not indexed*, so a
change made months ago is still visible rather than forgotten.

### The whole site

The company details a search engine reads: the legal name, what else you are called, the slogan,
the year founded, the areas served, what the company offers and knows about. Also the site's default
share picture, the browser colour in light and dark mode, and the language.

**Social profiles** are rows — add, reorder, and hide one without deleting it. These tell a search
engine which accounts elsewhere are this same company.

**Opening hours** are rows too, and they are separate from the hours written on the contact page on
purpose: these are the machine-readable kind — which days, opens at, closes at — and only that shape
can appear in a search result.

**The offices are not here.** Addresses and telephone numbers come from the Contact editor, so they
are only ever typed once.

### Crawling and installing

**Blocked paths** — one address per line that crawlers should not visit. Adding one is confirmed
first. A rule that would block the whole site is refused; that is not a stricter rule, it is the off
switch.

**Search Console and Bing verification.** Paste the token each service gives you, save, and the tag
is live on every page. **This is the most valuable thing on this screen and it is a text box.**
Until it is done you cannot see what the site ranks for, submit the sitemap, or be told that Google
refused to index something — the site is well built and invisible to you.

**The app manifest** is what a phone uses when somebody adds the site to their home screen: the
name, the short name, the colours.

### Google Analytics

Paste the measurement id from your Google Analytics property — it looks like `G-XXXXXXXXXX` — and
every page starts reporting to it on the next load. Clear the field and it all stops, just as
quickly. There is no deploy either way.

**This is the only thing on the site that loads anything from another company's servers**, which is
why it is off until you switch it on. An id that is not the shape Google issues is refused, and the
screen says so rather than quietly emptying the box.

**Two things change the moment you turn it on, and neither is automatic.**

1. **The privacy policy stops being true.** It says today, in as many words, "No cookies, no
   analytics, no tracking". That sentence has to be corrected on the Privacy Policy screen. It is
   the page people and regulators read to find out what the site does.
2. **EU visitors generally have to be asked first.** One of the three offices is in Brussels, and
   European rules usually require consent *before* analytics runs. This screen turns the
   measurement on; it does not ask anybody's permission, and nothing here can decide that for you.

If you are not ready for either, leave the field empty — Search Console, on the same screen, tells
you what people *searched* for without collecting anything from visitors at all.

### What none of this can do

It sets the ceiling; it does not set the position. Where the site ranks for a competitive search is
decided mostly off the site — other sites linking to it, people searching for the brand by name, and
how much substantial material there is to read. The four things that move it further than any change
here: verify in Search Console and submit the sitemap; claim a Google Business Profile for each
office; publish something substantial regularly; and earn links from partners, clients and
certification bodies.

---

## Your account

**Admin → Account**

| | |
|---|---|
| Change your password | ends every other signed-in session |
| Pair a new authenticator | do this when you change phone, **before** you wipe the old one |
| Issue new recovery codes | the old ten stop working |
| Sign out other devices | when you have used a shared machine |
| Recent activity | the last fifteen sign-in events |

Every one of these asks for your password again, even though you are signed in. That is deliberate:
a session left open on a shared machine should not be enough to take the account.

### Changing phone

**Pair the new phone before wiping the old one.** If the old one is already gone, use a recovery code
to sign in, then pair. If both are gone: [rung 3](secrets-recovery.md#3-phone-and-codes-gone).

---

## What cannot be edited here

Four editable pages today: **Careers**, **Contact**, **Company Profile** and **About Us**. The
other twelve are built into the site and need a developer and a deploy.

| | |
|---|---|
| Home, Services, Resource Certifications, and the rest | a developer |
| Images anywhere | a developer |
| The footer's contact details | a developer, after you change them here |
| Navigation | a developer |

The admin's Overview page says the same thing, plainly, so nobody has to guess.

Making more pages editable is planned work —
[adding-an-editor.md](../10-development/server-side/adding-an-editor.md).

---

## Enquiries

The contact form emails **info@tech4time.bd**. Pressing reply reaches the visitor.

There is no inbox in the admin and no copy stored on the server — the form sends mail and keeps
nothing. If an enquiry is deleted from that mailbox, it is gone.

Five submissions an hour are allowed from one address, which stops a bot flooding the inbox without
inconveniencing a real person.

---

## Habits worth having

**Before a busy period** — check you can still sign in, and that your recovery codes are where you
think they are.

**When posting a job** — check `/pages/careers/` afterwards. The admin shows a *View page* link.

**When changing contact details** — tell whoever deploys, so the footers get updated.

**Once a year** — issue fresh recovery codes and change your password. Both are two minutes on the
Account page.
