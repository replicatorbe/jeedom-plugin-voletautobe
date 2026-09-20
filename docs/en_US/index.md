# Auto Shutters

Open your roller shutters in the morning, close them in the evening, without
writing a single scenario — and do not do it when the temperature says
otherwise.

The plugin drives no hardware of its own: it commands the shutters your other
plugins have created — Zigbee, Z-Wave, Somfy, KNX, MQTT, 433 MHz modules.
Anything in Jeedom that can go up, go down or move to a percentage can join a
group.

## Before you start

Set the position of your installation in **Settings → System → Configuration →
General**. Without a latitude and a longitude, sunrise and sunset times are
wrong all year long, the sun's position in the sky just as much, and nothing but
this plugin will tell you.

It is the same position Jeedom already uses for `#sunrise#` and `#sunset#` in
scenarios, so the plugin's times and your scenarios' times cannot diverge. It is
also what makes it possible to work out where the sun is at any moment, and
therefore which facade it strikes.

If you intend to use the temperature conditions, also pick a default sensor in
the plugin configuration. A house has one outdoor temperature, not eight:
setting it once saves you from choosing it group by group, and a group can
always have its own if need be — the north-facing bedroom, a conservatory.

## One convention to remember: 0% = closed, 100% = open

Everywhere in the plugin, in the interface as in the commands, **0% means closed
and 100% means open**. It is the Jeedom core's convention for the *Flap* generic
type, and it is the one the plugin displays.

Not every shutter honours it: some modules publish 0 for "wide open" and 100 for
"closed". Those are corrected shutter by shutter, with the **inverted** checkbox
in the selector — see below.

## Creating a group

A **group** holds the shutters you open and close together: the south facade,
the ground floor, the bedrooms. A group has four moments — morning, sun
protection, end of protection, evening — and that is all there is to set.

1. **Plugins → Automation → Auto Shutters → Add a group.** Name it after what it
   commands.
2. **Choose the shutters.** The button opens the selector.
3. **Shutters tab.** Say which way the facade looks, and pick the temperature
   sensor if you intend to use the conditions.
4. **Schedule tab.** Set the morning, set the evening, and the sun protection if
   you want one.
5. **Save.** There is nothing else to do.

### One group per facade

This is no longer just advice, it is how the plugin is built: **the orientation
belongs to the group.** It is written once, in the *Shutters* tab, and all four
moments refer to it — the one that fires when the sun comes onto the wall as
much as the one that checks that it really is there.

A group mixing the south facade and the north facade would therefore have no
orientation to declare: the two walls do not catch the sun at the same hours,
and a sun protection set for one would be wrong for the other. Cut by facade —
the living-room window to the south-west, the bedrooms to the east — and
everything else sets itself. One group per floor, on the other hand, matches
nothing the sun does.

## The shutter selector

The selector walks through your installation and shows only what can move,
grouped by room. Four families, three of them shown by default:

| Family | What it is |
|---|---|
| **Shutters** | The device carries the core's *Flap* generic types. No doubt possible. |
| **BSO** | An adjustable sun blind. It is one, but it is driven differently: the plugin shows it apart so you know what you are ticking. |
| **Others** | Neither of the above, but commands named "Up", "Down", "Open", "Position". Many older modules leave the generic types empty; without this safety net their shutters would be unreachable. |
| **All devices** | Everything carrying an action command, including what the plugin cannot make sense of. Open it only when your shutter appears nowhere else: there, you name the command that goes up, the one that goes down, the one that stops and the one that positions. |

You tick **devices**, not commands: the plugin keeps track of which command goes
up, which goes down, which stops and which carries the percentage. A shutter
with only a slider, with no up or down button, is accepted: the plugin will send
it 100% to open and 0% to close. A device that only knows how to stop is not a
shutter and is never offered.

The **⚙** button on each line unfolds the four commands kept and lets you change
them: a module whose "Up" is called "Pulse 1", a two-relay device where only one
carries the shutter, a blind whose position is set by a command not called
"Position" — all of that is fixed there, without leaving the selector.

### The three buttons that move the shutter

Every line carries an **up**, a **stop** and a **down** button, which act for
real, right away, on that one shutter. A dot shows its position when the device
publishes one.

It is the only way to find out which of your three shutters is called "Module
3". A name cannot be checked from memory; a shutter that moves can. The **stop**
button is there to recover: send it down, recognise it, stop it halfway, and
close the selector with the right box ticked.

### The "inverted" checkbox

Unfold a line and you will find, under the four commands, an **inverted**
checkbox. Ticked, it tells the plugin that this particular shutter speaks
backwards: its "up" goes down, its "down" goes up, and the position it publishes
is counted from the other end.

It exists because the 0% = closed convention is not honoured everywhere, and
because the resulting failure is a particularly unpleasant one: the group closes
in the evening, and one shutter out of six opens wide. The symptom is visible,
the cause is not — especially if the offending shutter is in a room nobody
enters at night.

The safest way to set it is the line's up button: if the shutter goes down, tick
the box. One try, once, and it is settled for good.

## The four moments

The four moments are set in exactly the same way, and are read in the order of
the day:

| Moment | What it does | When, by default |
|---|---|---|
| **Morning** | open | at sunrise, not before 07:00 — **enabled** |
| **Sun protection** | move to 30% | when the sun reaches the facade — disabled |
| **End of protection** | open | when the sun leaves the facade — disabled |
| **Evening** | close | at sunset, not before 18:00 — **enabled** |

Only the morning and the evening are active on creation: they are the two
everybody wants. The other two come as a pair, to be enabled together the day
you deal with the sun — one closes, the other opens again.

**Do** — open, close, or move to a percentage. The morning opens and the evening
closes by default, but nothing forces you to stay there: a morning set to "move
to 60%" lets the light in without putting the bedroom on show from the street.

**When** — five possibilities:

- **At a fixed time**: 07:00, all year round.
- **Relative to sunrise**: so many minutes before or after.
- **Relative to sunset**: likewise.
- **When the sun reaches the facade**: at the first moment of the day when the
  sun lights that wall, whatever the season.
- **When the sun leaves the facade**: at the first moment it stops lighting it —
  because it has turned past the wall, because it has dropped too low, or because
  it is setting.

The last two ask for no angle: the facade is already written in the group's
*Shutters* tab, and they refer to it. A whole section below is devoted to them.

**Days** — the weekdays concerned. No weekday ticked means *never*, not *every
day*: that is what one expects after unticking everything to suspend a moment. A
morning set on working days, and another group for the weekend, is this plugin's
most common setting.

**Time guards** — "not before" and "not after". In Brussels the sun sets at 4:40
pm on 21 December and at 10:00 pm on 21 June: a closing tied to sunset would
drift by five hours over the year, and would shut the living-room shutters in
the middle of Christmas tea. "Not before 18:00" brings winter evenings back to
18:00 — a guard **brings back**, it does not cancel. The same reasoning holds in
the morning: "not before 07:00" keeps an opening tied to sunrise from waking the
house at 5:30 am in June. Leave it empty to follow the sun all year.

**Random offset** — presence simulation. The moment is moved earlier or later at
random, within this limit. The draw happens **once a day**: the time shown in
the preview is the one that will really be played, and two groups set the same
way do not move on the same second. Shutters closing every evening at 21:00
sharp are noticeable from the street; that is precisely what one is trying to
avoid when going away.

**Next times** — under each moment, the next three occurrences, computed by the
very code that will decide the order when the time comes. A moment that is never
announced will never fire: disabled moment, no weekday ticked, missing
installation position, or a facade the sun never reaches at this time of year.

### The sun protection, and its end

The third moment is the one that justifies the temperature sensor on its own:
closing the shutters **three quarters of the way** during the hot hours, **on hot
days only**, and doing nothing the rest of the year.

It ships disabled, set to "move to 30%", "when the sun reaches the facade",
"only when the sun is on the facade" — a condition with no effect under that very
trigger, as we shall see below — and "only if the temperature is ≥ 26 °C".
Set that way it does nothing from April to June, closes three quarters of the
way during a heatwave, and falls silent again in September — without you having
to enable or disable it as the seasons go by.

30% rather than 0% is not a whim: a fully closed shutter makes a room dark at
midday. Three quarters of the way, the heat is stopped and there is still enough
light to live without switching anything on.

The fourth moment, **End of protection**, is its necessary counterpart: it opens
the shutters again when the sun leaves the facade. Without it the room would
stay at 30% until the evening, long after the sun has moved on. Both moments are
set on the group's facade, and the next section explains how.

## The temperature condition

Every moment can be made conditional: **none**, **only if the temperature is ≥** a
value, or **only if it is ≤** a value. The value is a decimal number — half a
degree matters for a frost threshold, and "5.5" is written as it is said.

Both uses are symmetrical and both concrete:

- **In the morning, "only if ≥ 5 °C".** In winter, a closed shutter insulates.
  Opening it at 7 am at −3 °C loses heat all morning for three hours of grey
  light. Below the threshold, the plugin leaves the shutters closed.
- **The sun protection, "only if ≥ 26 °C".** Described above.
- **In the evening, "only if ≤ 18 °C".** For those who want to keep the cool of a
  summer evening as long as possible and only close once it has passed.

The sensor is an information command of your installation: an outdoor probe, the
temperature of a weather station, that of a thermostat. It is chosen in the
group's *Shutters* tab, from a list the plugin fills by itself, and the current
reading is shown next to it so you can see at once whether you picked the right
one. Leaving the list on its empty option means "the plugin's one", set once and
for all in the configuration.

### A silent sensor blocks nothing

**If the sensor is missing, broken, or returns an unreadable value, the moment is
played anyway.** It is the plugin's most important decision, and it is
deliberate.

A temperature condition is a *refinement*: moving is the normal behaviour. The
day a sensor battery dies, the house must go on opening its shutters in the
morning — not stay in the dark for three weeks while somebody works out why. The
doubt benefits the movement, and the plugin writes in its log that it moved
without having been able to check.

The failure still shows: the **Health** page counts the groups that set a
temperature condition with no readable sensor, and the moment's preview says so
under the setting.

### The decision is taken only once

The condition is evaluated at the scheduled time, once, and the moment is marked
played whether it moved or not. It is not replayed during the catch-up window.

That is intentional: without it, a morning skipped at 07:00 for 4 °C would fire
on its own at 07:12 because the sun had warmed the sensor. A shutter setting off
a quarter of an hour later, with nothing having asked for it, is exactly what one
does not want from an automation. The decision is taken at the scheduled time;
what was not done that day will not be done.

When a moment is skipped, the log and the **Last change** command say so with the
figures: "Morning skipped: 1.5 °C, threshold 5 °C". You never have to guess why
the shutters did not move.

## The sun's position

A fixed time is only an approximation of what one is really after. The 13:00
that suits June lets the sun beat down an hour too long in August, and means
nothing at all in October. The real question is not what time it is, it is
**where the sun is**: a south-west facade catches the sun when the sun comes
round to face it, at about 200°, and that is not at the same time from one month
to the next.

The plugin can work out the sun's position above your house at any moment. You
have only one thing to teach it: **which way this group's facade looks**. It is
written once, and everything else refers to it — the moments that fire on it as
much as the ones that are made conditional on it.

### The facade is declared once, on the group

The *Shutters* tab carries a **Facade** block, next to the temperature sensor.
Three numbers, describing the same wall from three sides:

| Field | What it says | Shipped at |
|---|---|---|
| **from** | the azimuth at which the sun **comes onto** the facade | 135° (south-east) |
| **to** | the azimuth at which it **leaves** it | 315° (north-west) |
| **above** | the **minimum elevation** below which it does not really strike | 15° |

Those three numbers are not three independent settings: **together they define
one single thing — the time of day when the sun lights that facade.** The sun is
on the facade when its azimuth is inside the *from … to* window **and** its
elevation is above the minimum; as soon as either of the two stops being true, it
is no longer on it. The minimum elevation is therefore not a condition laid on
top of the azimuth: it is half the definition, and the section "Reaching the
facade, leaving it" shows that it is most often the one that decides.

Under those three fields, the block shows the sun's position **right now** at
all times: that is the tool that lets you fill them in without measuring
anything, and the next paragraph gives its instructions for use.

Those three numbers are written **once for the whole group**, and no moment asks
for them again. A moment triggered on the facade refers to them; a sun condition
refers to them too. You therefore cannot write your house's orientation in two
places and then wonder which of the two is authoritative. The day you correct
one of those numbers, all four moments of the group follow without you touching
them.

The shipped values — **135° to 315°, above 15°** — cover the southern half of
the sky, from south-east to north-west. That is a south-facing facade in the
broad sense, and a reasonable starting point until you have taken a reading of
your own.

### Azimuth, elevation, and the direction in plain words

The **azimuth** is the direction the sun comes from, counted in degrees from
north, clockwise — exactly as on a compass:

| Azimuth | Direction |
|---|---|
| **0°** (or 360°) | north |
| **90°** | east |
| **180°** | south |
| **270°** | west |

The sun rises in the east, passes south in the middle of the day and sets in the
west: at our latitudes its azimuth only ever increases from sunrise to sunset. At
50.5° north — Belgium, northern France — it starts at 50° at sunrise on 21 June
and ends at **310.1°** at sunset; on 21 December it only goes from 127.5° to
**232.5°**, which is another way of saying that the winter sun never visits a due
east or a due west facade.

Remember that **310.1°**: it is the furthest round the sun ever comes here, on
the evening of the summer solstice. Never beyond. A facade ending at 315° — the
one the plugin ships — is therefore **never left by the sun turning**: the sun
sets before it gets there. That single figure governs the rest of this section,
and it is counter-intuitive: at our latitudes the sun leaves a west-facing wall
by going down, not by going round.

The **elevation** is the angle of the sun above the horizon. It is 0° at sunrise
and at sunset, it is negative at night, and it peaks at 62.6° at midday on 21
June in Brussels against a mere 15.7° at midday on 21 December. That same
elevation is what makes the difference between a sun that heats a room and a sun
that lights the wall opposite.

Wherever the plugin shows an azimuth, it gives its name as well: "200°
(south-south-west)". A figure cannot be checked from memory; a direction can.

### Finding which way your facade looks, without a compass

You need neither a compass nor to look your house up on a map. A group's
*Shutters* tab shows the sun's position **right now** at all times, just under
the Facade block: "Sun at 217° (south-west), 31° high".

It is the plugin's setting-up tool, and it takes three steps:

1. **Wait for the moment the sun strikes the facade** — the one that bothers
   you, the one where the room heats up, the one where you would lower the
   shutter by hand.
2. **Open the group's page** and read the azimuth shown. It is the sun's
   direction at that instant, and therefore roughly the orientation of your
   facade.
3. **Copy that figure into the *from* field.**

Do it again late in the afternoon, when the sun leaves the facade and the room
stops heating up: that second reading goes into the *to* field. Two glances out
of the window, and your facade is declared.

It is the less critical of the two readings: at our latitudes what takes the sun
off a west-facing wall is almost always its drop below the minimum elevation, not
its ending azimuth. A rough *to*, taken a little wide, does no harm.

One reading on one day holds for the whole year. The azimuth at which the sun
comes round to face your wall does not depend on the season: only the time at
which it gets there changes, and that is precisely what the plugin takes care
of.

If you would rather reason from the house plan: a due south facade looks at
180°, a south-west one at 225°, a west one at 270°, a south-east one at 135°.
Then take the *from* and the *to* on either side — a south-west facade catches
the sun roughly from 180° to 270°.

### Triggering: the five ways of timing a moment

A moment's *When* list holds five entries, two of which use the group's facade:

| When | The moment fires… |
|---|---|
| **At a fixed time** | at the given time, all year round. |
| **Relative to sunrise** | so many minutes before or after sunrise. |
| **Relative to sunset** | so many minutes before or after sunset. |
| **When the sun reaches the facade** | at the first instant of the day when the sun is on the facade. |
| **When the sun leaves the facade** | at the first instant it no longer is. |

The two facade modes **open no angle field**: the angle is already written in
the *Shutters* tab. They offer only the offset in minutes and its direction, as
the sunrise and sunset modes do — and it earns its keep: "20 minutes after the
sun reaches the facade" gives the wall time to heat up before you close. The
**time guards**, the **weekdays** and the **random offset** then apply, with
nothing special about them.

Both modes follow the season with nothing for you to adjust: the sun comes onto
a south-west facade earlier in the afternoon in December and later in June, and
the moment moves with it. It is the same principle as the sunrise and sunset
modes, applied to a wall rather than to the horizon.

### Reaching the facade, leaving it

The sun is **on the facade** when its azimuth is inside the *from … to* window
**and** its elevation is above the minimum. The two triggers do nothing more than
watch for the first instant that sentence becomes true, and then for the first
instant it stops being true.

**The sun reaches the facade** in three ways:

- it **crosses the starting azimuth** — the ordinary case, the early afternoon on
  a south-west facade;
- it **rises already facing the wall**, when the minimum elevation is low or
  zero: a due east facade, set from 45° to 135° above 0°, catches the sun as soon
  as it appears at 50° on 21 June;
- it **climbs above the minimum elevation** while already facing the wall — a
  south-east facade starting at 120°: on 21 December the sun rises at 127.5°,
  squarely inside the window, but it grazes the horizon for a good while before
  it passes 15° high, and that crossing is its arrival.

**The sun leaves the facade** in three symmetrical ways, and it is the first of
the three that counts:

- it **crosses the ending azimuth**;
- it **drops below the minimum elevation**;
- it **sets** — that last case is the one of a zero or negative minimum
  elevation; with 15°, the sun goes under the bar a good while before it
  disappears.

Those last two ways of leaving are not textbook cases, they are the frequent
ones. Remember the **310.1°**: at our latitudes the sun never comes round any
further. A facade shipped from 135° to 315° is therefore **never** left by the
sun turning, not on a single day of the year — 232.5° at sunset on 21 December,
310.1° at sunset on 21 June, and nothing in between that reaches 315°. Had the
plugin looked at the azimuth alone, "End of protection" would not have fired once
in the whole year, the shutters would have stayed at 30% until the evening, and
the failure would have been perfectly invisible. What opens them again is indeed
the drop below 15°, or sunset.

On the days the sun never reaches the facade at all, **neither of the two moments
fires**, and the preview of the next times says so. That is the normal case for a
due east facade in December, when the sun already rises at 127.5°: it will never
pass through the east. It is also the case of a facade whose minimum elevation is
raised to 20°: on the days the sun does not climb that high, there is neither an
arrival nor a departure. The plugin then falls back neither on midnight nor on
sunrise, which would be the worst possible answer: nothing appears in the next
times, and nothing moves.

### Making it conditional: only when the sun is on the facade

The trigger says **when**, the condition says **if**. They are two different
questions, and that is why there are two settings — but they are not asked
together: the condition is meant for the moments triggered **otherwise** than on
the facade, at a fixed time, at sunrise or at sunset.

Just like the temperature, every moment can be made subject to a **sun
condition**, which has only two positions:

- **None** — the moment does not look at the sun.
- **Only when the sun is on the facade** — the moment moves only if the sun is
  between the group's two azimuths **and** above the minimum elevation, that is,
  if it is on the facade in the exact sense of the section above.

There is nothing else to type in: the condition uses the group's facade, the
very one written in the *Shutters* tab, and a help line recalls it under the
setting so that you do not have to switch tabs.

Like the temperature condition, it is evaluated at the scheduled time, once, and
the moment is marked played whether it moved or not.

When both conditions are set, the plugin looks at **the sun first**. A sun
protection that does not fire on an overcast day at 27 °C must not be reported
as "skipped: too hot" when the real reason is that the sun was not on that
facade — and that is by far the more frequent of the two.

### With a facade trigger, the condition has nothing left to filter

This is the question one asks on seeing the two settings side by side, and it
deserves a plain answer rather than a silence.

A moment triggered **when the sun reaches the facade** fires, by definition, at
the first instant the sun is on the facade — azimuth inside the window **and**
elevation above the minimum, both at once, since that is what "being on the
facade" means. Asking it on top of that for "only if the sun is on the facade"
therefore filters **nothing at all**, not even the elevation: the answer is yes
by construction. The page says so under the setting, and leaving the condition on
**None** costs you nothing.

It is not merely an economy. Re-testing what the trigger has just set to the
degree is dangerous: a rounding in the computation can place the sun a
thousandth of a degree **outside** the facade, and the moment would then be
skipped every single day, for a perfectly invisible reason.

With **leaves the facade** it is worse, and the other way round: at that very
instant the sun has just stopped being on the facade. A sun condition there would
be false every day, the reopening would never happen, and the shutters would stay
at 30%. That is why "End of protection" ships with no sun condition.

The condition, on the other hand, keeps its full meaning with the **other**
triggers: "at 13:00, but only if the sun really is on this facade" is a complete
setting, where the azimuth window and the minimum elevation both serve fully.
That is where it belongs.

### Why a minimum elevation

Because the azimuth alone does not tell the whole story, and that is precisely
why the elevation is part of the definition of the facade rather than a condition
laid on top of it. A sun below the horizon has a perfectly well-defined and
perfectly irrelevant azimuth, and a sun grazing the rooftops at 8° high heats
nothing: its rays cross far more atmosphere, and they are most often stopped by a
hedge, a tree or the house opposite. Without a minimum elevation, a December
morning would fire a sun protection nobody needs, and leave the living room in
the dark on the gloomiest day of the year.

It is also the elevation that makes the sun **leave** a west-facing facade at the
end of the day. The sun will never come round beyond 310.1° of azimuth, but it
drops below 15° every evening of the year: on a facade ending at 315°, it is the
minimum elevation, and it alone, that puts an end to the sun protection.

The minimum elevation doubles as a seasonal guard. In Brussels the sun peaks at
15.7° on 21 December: with a 15° minimum, it is on the facade for only a few tens
of minutes around midday that day, and it is not on it at all if you ask for 20°.
That is exactly what one wants from a sun protection — that it falls silent in
winter without having to be disabled.

When a moment is skipped because the sun was not on the facade, the log and the
**Last change** command name the real reason, with the figures:

- "Sun protection skipped: sun at 8.4°, minimum 15°"
- "Sun protection skipped: sun at 112° (east-south-east), facade 135°–315°"

The elevation is looked at before the azimuth: when the sun is too low, "too low"
is the useful reason to show. Those two lines can only appear on a moment
triggered by something other than the facade — a fixed time, a sunrise, a sunset.
A facade trigger only ever fires when both are already true.

### The facade may pass through the north

A north-west facade catches the sun late on a summer day, a north-east one early
in the morning. A facade running **from 300° to 30°** is therefore perfectly
legitimate: it starts at north-west, passes through north and stops at
north-north-east. The plugin reads it in that order, crossing 0°, and a sun at
350° is indeed on it. There is nothing else to tick: it is enough for the
**from** to be greater than the **to**.

The only meaningless form is a facade whose start and end are equal: it contains
nothing at all, so the sun never reaches it, the two facade triggers fire on no
day whatsoever, and a sun condition set on it would never be met. For "the whole
sky", pick the **None** condition.

### The fourth moment: the end of protection

The sun protection closes three quarters of the way. With nothing else, the
shutters stay at 30% **until the evening**: the sun moved west two hours ago,
there is nothing left to protect, and the room stays in the gloom for nothing.
That is what one notices after three days of heatwave, and it is exactly what
the fourth moment fixes.

**End of protection** opens the shutters again **when the sun leaves the
facade**. It ships disabled, set to "open", and its trigger is already the right
one: you only have to enable it along with the sun protection. The two come as a
pair — one closes as the sun arrives, the other opens as it leaves — and
enabling the first without the second is the surest way to spend your summer
afternoons in the dark.

And "leaving the facade" does mean all three things seen above. This is where it
matters most: with the shipped facade, from 135° to 315°, the sun's azimuth never
goes beyond 310.1°, and this moment would never fire if the plugin stuck to the
azimuth. What opens the shutters again is the sun dropping below the minimum
elevation — or setting, if you have taken that elevation down to zero.

It ships **with no sun condition**, and that is not an oversight. At the moment
the sun leaves the facade it is by definition no longer on it: setting the
condition on that moment would make it false every time, the reopening would be
skipped every day, and the shutters would stay at 30% — precisely what the
moment is there to prevent. It is the trap of the section "With a facade
trigger", seen from the other side.

If you would rather open again a little after the sun has gone, the offset is
there for that: "30 minutes after the sun leaves the facade" gives the wall time
to stop radiating. And the **not after** guard brings back to 20:00 a reopening
that would fall at 21:30 in June, just before the evening closing.

### A south-west facade, from end to end

A living room whose picture window looks south-west, with a complete sun
protection. It all fits in one group, and the orientation is written there only
once.

**In the *Shutters* tab, the facade:**

| Setting | Value |
|---|---|
| **Facade, from** | **200°** (south-south-west) — the sun arrives |
| **Facade, to** | **290°** (west-north-west) — the sun leaves |
| **Minimum elevation** | **15°** |

Both azimuths were read one June day, at the window: one reading at the moment
the room starts heating up, another at the moment it stops.

**In the *Schedule* tab, the sun protection:**

| Setting | Value |
|---|---|
| **Do** | move to 30% |
| **When** | when the sun **reaches the facade** |
| **Not before** | **11:00** |
| **Sun condition** | none |
| **Temperature condition** | only if ≥ **26 °C** |

**And the end of protection:**

| Setting | Value |
|---|---|
| **Do** | open |
| **When** | when the sun **leaves the facade**, 15 minutes after |
| **Sun condition** | none |
| **Temperature condition** | none |

It reads as a sentence: *close three quarters of the way when the sun comes
round to face the window — so when it really is there, and high enough to heat —
never before 11:00, and only if it is hot; open again a quarter of an hour after
it has gone.*

What that gives over the year is worth a close look, because it is the
surprise of the setting: **the 290° never serves.** At the summer solstice the
sun drops below 15° high at 288.8° of azimuth, a hair short of 290° but short of
it; at the equinox it drops below 15° as early as 251°, an hour and a half before
setting at 270°; in December it never climbs to 15° at all. In other words, on
this window it is the minimum elevation that opens the shutters again **every day
of the year**, and a plugin that had looked at the azimuth alone would never have
opened them. The 290° is still useful — it says where the wall stops — but it is
not what fires.

Every line has its reason:

- **200° and 290° are the orientation, and nothing else.** They are written
  once, for the group. The day you realise the room already heats up when the
  sun is at 190°, you correct one number and both moments follow.
- **The trigger is the *when*.** The moment follows the sun, not the clock: it
  fires in the early afternoon all year round, but never at the same moment two
  days running, and there is no 13:00 left to adjust twice a year.
- **11:00 is a safety net.** On most days it has nothing to bring back: in
  Brussels the sun only reaches 200° past the middle of the day, in every
  season. It costs one line and it bounds the damage on the day a figure is
  mistyped — a 100° instead of a 200° in the *from* field would close the living
  room in the middle of the morning.
- **15° is the facade's third bound**, not a minor setting. It is what says from
  which point the sun on this window really heats, and it is what takes the sun
  out of the facade every evening, well before the azimuth ever gets there.
- **The sun condition has nothing to do here, and that is why it is on *none*.**
  The trigger has already set the whole facade, azimuth and elevation included:
  at the instant it fires, the condition would be true by construction. The
  plugin ships the sun protection with the condition set; leaving it changes
  nothing, putting it on *none* changes nothing either. It is with a fixed-time
  trigger — "at 13:00" — that it would do its work.
- **26 °C is the second *if*.** A sunny March afternoon needs no sun protection,
  and 26 °C says so better than a date does.
- **The quarter of an hour before reopening** gives the wall time to stop
  radiating. Without that moment, the living room would stay at 30% until the
  evening closing.

### Without an installation position, the sun is wrong

The computation needs a latitude and a longitude. If the installation position
is not set, the azimuth and the elevation are those of the Gulf of Guinea, that
is to say wrong, and **the moment is played anyway**: the sun condition blocks
nothing, exactly as a silent temperature sensor blocks nothing. A condition is a
refinement, moving is the normal behaviour, and the doubt benefits the movement.

A moment triggered on the facade, on the other hand, will fire at an instant
that means nothing — the sun that is computed is not the one lighting your wall.

The failure still shows: the **Health** page counts, on a "Sun window" line, the
groups that use the sun with no installation position, and the moment's preview
says so under the setting. The cure is two fields, in **Settings → System →
Configuration → General**.

## The commands created

| Command | Type | Role |
|---|---|---|
| **Next change** | info | "Close today 21:14", or "Paused". Visible on the dashboard. |
| **Open** / **Close** / **Stop** | action | Acts on the whole group, by hand or from a scenario. |
| **Position** | slider action | Moves the whole group to a percentage. 0% = closed, 100% = open. |
| **State** | numeric info | The group's position, the average of those its shutters publish. Logged. |
| **Temperature used** | numeric info | The reading the conditions were evaluated on. Logged: it is what explains, three days later, why the morning was skipped. |
| **Last change** | info | "Opened today 07:12 (schedule)", or "Morning skipped: 1.5 °C, threshold 5 °C". Answers "did it work this morning?" on its own. |
| **Schedule active** | binary info | 0 when the group is paused. Logged. |
| **Pause** / **Resume** | action | Holiday mode, drivable from a scenario. |
| **Next morning** / **Next protection** / **Next end of protection** / **Next evening** | info | The four appointments separately. |
| **Sunrise** / **Sunset** | info | Today's times, useful in scenarios. |
| **Sun azimuth** / **Sun elevation** | numeric info | Where the sun is right now, in degrees. Not logged: the sun's position is a computation and not a measurement, and storing it would amount to keeping an ephemeris table one can rebuild on demand. |

Only the first four are visible on creation; the others are created hidden, to be
shown again if you have a use for them.

**"State" is the shutters' state.** It is the average of the positions the
group's shutters publish, refreshed every minute, and it follows what really
happens — including when someone presses the wall switch. Shutters that do not
publish their position — many 433 MHz modules, for instance — can say nothing; if
no shutter of the group publishes one, "State" keeps the memory of the last order
sent, for want of anything better. What the plugin asked for is always in "Last
change".

If you rename a command or change its visibility, the plugin will not contradict
you: it resets the type and the generic type on every save, never the name nor
the visibility. They are yours as soon as you have touched them.

## What happens when a shutter cannot do something

The shutters of one group do not all have the same talents, and the plugin makes
do with what it finds:

- **Open** with no "up" command: the slider is sent to 100%.
- **Close** with no "down" command: the slider is sent to 0%.
- **Move to 30%** with no slider: the shutter is **closed** (below 50%) or
  **opened** (from 50% up), and the log says so. An all-or-nothing shutter in a
  group set to 30% must not stand still without explanation.
- **Stop** with no stop command: the order is ignored for that shutter, with an
  explicit message. Many shutters cannot stop, and that is not a failure.

## Pausing a group

Going away for a fortnight, or simply wanting to sleep with the shutters open
this Sunday, has nothing to do with disabling the device: that would take it off
the dashboard, its buttons would stop answering and its commands would vanish
from scenarios.

**Pause** stops all four moments, and nothing else. The group stays whole, you
can still open and close it by hand, and its tile shows "Paused" instead of
announcing an appointment it would not honour. The button is in the *Shutters*
tab, and the **Pause** and **Resume** commands can be driven from a scenario —
that is how a holiday mode, a presence detector, or a wind alarm that raises the
blinds and forbids the plugin to lower them again is wired in.

The pause is stored in the database, not in the cache: it survives a reboot, an
update and a cache flush. A paused and forgotten group being the plugin's
quietest failure — everything works, and nothing moves — the Health page counts
them, and the home page marks them in orange.

## Catch-up

The cron runs every minute. If the box was off at the scheduled time, the moment
is still played during the **catch-up** window — 15 minutes by default,
adjustable in the plugin configuration. After that it is dropped: opening the
morning shutters at four in the afternoon helps nobody, and closing them at three
in the morning even less.

A moment is played only once a day, even if the cron runs sixty times during the
catch-up window.

## Frequently asked questions

**A shutter no longer moves.** Open the group: a device deleted from Jeedom
carries a "Device deleted" label, a disabled one carries its own. A failed order
also produces a message in Jeedom's message centre.

**A shutter goes the wrong way.** Open the selector, unfold its line with the ⚙
button and tick **inverted**. The line's up button lets you check right away.

**I renamed a shutter, do I have to pick it again?** No. Shutters are stored by
their identifier; the displayed name is refreshed every time the page is opened.

**My temperature sensor is broken, what happens?** The moments are played anyway,
without condition. That is deliberate: an automation must not fall silent because
a sensor has. The Health page counts the groups in that situation.

**How do I find out which way my facade looks?** Open the group at the moment
the sun strikes it and read the sun's position shown under the Facade block, in
the *Shutters* tab: "Sun at 217° (south-west), 31° high". Copy that figure into
the *from* field, do it again when the sun goes away for the *to* field, and you
are done. Those azimuths do not change with the season.

**Does my sun condition duplicate my facade trigger?** Yes, entirely. A moment
triggered when the sun reaches the facade only fires at the instant the sun is on
it — azimuth **and** elevation: the condition can only be true, it filters
nothing, and you may leave it on *none*. With *leaves the facade* it would even
be false every time, and would skip the reopening every day. The condition is
meant for the other triggers: "at 13:00, only if the sun is on the facade".

**My facade goes up to 315° and the sun never gets there: does the end of
protection still fire?** Yes. At our latitudes the sun's azimuth does not go
beyond 310.1°, not even on the evening of 21 June: it never leaves such a facade
by turning. It leaves it by going down — below the minimum elevation, then below
the horizon — and that is when the shutters open again. The same holds for any
west-facing facade: the sun leaves it by going down far more often than by going
round.

**My shutters stay at 30% all afternoon, why?** Because the sun protection is
enabled and the **end of protection** is not. The two come as a pair: the first
closes when the sun reaches the facade, the second opens again when it leaves.
Enable it in the *Schedule* tab.

**I have not set my installation position, what does that change for the sun?**
The azimuth and the elevation are computed for the Gulf of Guinea, so they are
wrong. The sun condition does not block anything for all that: **the moment is
played anyway**, without condition, just as with a silent temperature sensor. A
moment triggered on the facade, on the other hand, will fire at an instant that
means nothing. The Health page counts the groups concerned on a "Sun window"
line.

**Can the same shutter be in two groups?** Yes, but both groups will send it
their orders: the last one wins. That is useful for a sun protection covering
only part of a wider group; it is a nuisance if the two groups close at different
times.

**Can a group contain another group?** No. The selector does not offer itself: a
group containing itself would call itself endlessly.

**What about wind, rain, presence?** The plugin does not look at them. Those
conditions are a matter for scenarios, and a scenario has everything it needs: it
pauses the group, or it calls **Open**, **Close** or **Position** directly. The
rule of thumb is simple: what repeats every day belongs in the plugin, what
depends on an event belongs in a scenario.

**Where is the log?** Analysis → Logs → `voletautobe`. Every order played leaves
a line with the scheduled time, the setting that produced it, and the temperature
used if there was a condition.
