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
wrong all year long, and nothing but this plugin will tell you.

It is the same position Jeedom already uses for `#sunrise#` and `#sunset#` in
scenarios, so the plugin's times and your scenarios' times cannot diverge.

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
the ground floor, the bedrooms. A group has three moments — morning, sun
protection, evening — and that is all there is to set.

1. **Plugins → Automation → Auto Shutters → Add a group.** Name it after what it
   commands.
2. **Choose the shutters.** The button opens the selector.
3. **Schedule tab.** Set the morning, set the evening, and the sun protection if
   you want one.
4. **Save.** There is nothing else to do.

One group per facade is often better than one group per floor: facades are what
catch the sun, and a midday sun protection only makes sense where it strikes.

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

## The three moments

The three moments are set in exactly the same way, and are read in the order of
the day: morning, sun protection, evening.

**Do** — open, close, or move to a percentage. The morning opens and the evening
closes by default, but nothing forces you to stay there: a morning set to "move
to 60%" lets the light in without putting the bedroom on show from the street.

**When** — three possibilities:

- **At a fixed time**: 07:00, all year round.
- **Relative to sunrise**: so many minutes before or after.
- **Relative to sunset**: likewise.

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
announced will never fire: disabled moment, no weekday ticked, or missing
installation position.

### The sun protection

The third moment is the one that justifies the temperature sensor on its own:
closing the shutters **three quarters of the way** during the hot hours, **on hot
days only**, and doing nothing the rest of the year.

It ships disabled, set to 13:00, "move to 30%", and "only if the temperature is
≥ 26 °C". Set that way it does nothing from April to June, closes three quarters
of the way during a heatwave, and falls silent again in September — without you
having to enable or disable it as the seasons go by.

30% rather than 0% is not a whim: a fully closed shutter makes a room dark at
midday. Three quarters of the way, the heat is stopped and there is still enough
light to live without switching anything on.

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
| **Next morning** / **Next protection** / **Next evening** | info | The three appointments separately. |
| **Sunrise** / **Sunset** | info | Today's times, useful in scenarios. |

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

**Pause** stops all three moments, and nothing else. The group stays whole, you
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
