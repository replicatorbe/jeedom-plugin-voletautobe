# Changelog

## 1.2

- **The end of protection no longer undoes what the protection did not do.** The
  two moments used to be independent of each other: on a 19 °C day the sun
  protection was skipped — threshold 26 °C, it was not hot enough — and at 18:03
  the end of protection opened the shutters all the same. If they were closed
  because somebody was having a nap, or so as not to be seen from the street, the
  automation undid a gesture nobody had asked it to undo, and it only showed
  afterwards. From now on the end of protection only opens again if the sun
  protection **really moved** the shutters that same day — not if it was merely
  evaluated. On the days it is skipped, the end of protection is skipped too, and
  it says so, in the log as in "Last change": "End of protection skipped: the sun
  protection did not happen today". The moment is marked played all the same: the
  decision is taken once, at the scheduled time, as it is for the conditions.
- **The exception matters just as much: when the sun protection is disabled, the
  end of protection acts on its own.** The coupling exists so as not to undo a
  protection that never happened; where no protection is configured there is
  nothing not to undo, and "open late in the afternoon, when the sun leaves the
  facade" is a legitimate setting in its own right. So it goes on playing every
  day, like the morning and the evening.
- **The plugin now says whether the sun reaches your facade.** At 50.5° north the
  sun never goes beyond 310.1° at sunset: a facade declared up to 340° is
  perfectly consistent on the screen and will nevertheless never end on its
  ending azimuth. The plugin walks a year of the sun's courses, one day in five,
  and writes under the facade fields what it really gives — "This facade is lit
  340 days out of 365, for up to 8 h 00 a day", or the fitting warning: never
  left on the ending azimuth, or never lit at all. The sentence refreshes while
  you are setting things up, instead of letting you find out months later.
- **A test button per moment.** It runs the moment's action for real — its "do",
  its percentage, its shutters — paying no attention to the conditions, a test
  button that did nothing because it is 18 °C being baffling, **and it reports
  what the conditions would have said**: "Close to 30% sent to 4 shutters. When
  the time came, this moment would have been skipped: 18.2 °C, threshold 26 °C."
  Both halves count, the second one above all: it answers, in the middle of
  March, a question one could only ask the next morning. The test does not mark
  the moment as played, it will still be played at its own time.
- **An adjustable delay between two orders, 400 ms by default.** A group of eight
  shutters means eight frames sent within the same millisecond; on 433 MHz one or
  two of them get lost, a shutter does not move, and **nothing points it out**
  since the command was duly played. The delay goes between the shutters and not
  after the last one, is set from 0 to 5000 ms in the plugin configuration, and
  the total wait of a group is capped at 30 seconds so as not to block the core's
  cron.
- **The Health page counts the missing shutters** — those whose device has been
  deleted from Jeedom. They fail on every order and feed the message centre, and
  as long as they are there the group commands fewer shutters than it shows. They
  carry the "Device deleted" label inside the group.

## 1.1

- **The facade belongs to the group.** The orientation is declared once and only
  once, in the *Shutters* tab: the azimuth at which the sun comes onto the wall,
  the one at which it leaves it, and a minimum elevation. Those three numbers
  together define one single thing — the time of day when the sun lights that
  facade: the sun is on it when its azimuth is inside the window **and** its
  elevation is above the minimum. The moments refer to it, they do not declare it
  again — a house's orientation is written in one single place. One group per
  facade becomes the normal way to cut things up.
- **Two new triggers**: *when the sun reaches the facade* and *when the sun
  leaves the facade*. The first is the first instant of the day when the sun is
  on the facade: it has crossed the starting azimuth, or it rises already facing
  the wall, or it has just climbed above the minimum elevation. The second is the
  first instant that is no longer true: it has turned past the ending azimuth, or
  it has dropped below the elevation, or it sets — whichever comes first. No
  angle to type in, only the offset in minutes and its direction, as with the
  sunrise and sunset modes. On the days the sun never reaches the facade, neither
  of the two is played, and the preview says so.
- **The sun leaves a west-facing facade by going down, not by going round.** At
  our latitudes — 50.5° north — its azimuth at sunset never goes beyond 310.1°
  (232.5° at the winter solstice, 310.1° at the summer one). A facade ending at
  315°, like the one shipped by default, is therefore never left by the sun
  turning: it is the drop below the minimum elevation, or sunset, that puts an
  end to the sun protection. A model that had looked at the azimuth alone would
  not have reopened the shutters on a single day of the year.
- **A fourth moment: the end of protection.** It opens the shutters again when
  the sun leaves the facade. Without it, the sun protection left the room at 30%
  until the evening, long after the sun had moved west. Shipped disabled, it
  comes as a pair with the sun protection.
- **A new condition per moment**: "only when the sun is on the facade" — azimuth
  inside the window and elevation above the minimum. It has nothing to type in,
  it uses the group's facade, and it earns its keep with the triggers that do not
  look at the facade: "at 13:00, only if the sun is on the facade".
- **With a facade trigger, the sun condition has nothing left to filter.** The
  moment only fires at the instant the sun is on the facade, azimuth and
  elevation included: the condition is true by construction, the azimuth is not
  tested again — a rounding in the computation could have made it false by a
  thousandth of a degree and skipped the moment every single day — and the page
  says so under the setting. With *leaves the facade* the condition would even be
  false every time: that is why "End of protection" ships without it.
- The minimum elevation is not one more condition, it is part of the definition
  of the facade. It keeps a grazing winter sun from firing a sun protection
  nobody needs, and it is what takes the sun out of the facade at the end of the
  day.
- The facade may pass through the north: "from 300° to 30°" does contain a sun at
  350°.
- The sun's position is shown at all times under the Facade block — "Sun at 217°
  (south-west), 31° high": that is how one finds out which way a facade looks
  without going out with a compass.
- Azimuths are named wherever they are shown: 200° reads "south-south-west".
- Three more information commands, created hidden: sun azimuth and sun elevation,
  not logged, and "Next end of protection".
- When both conditions are set, the sun is evaluated before the temperature, and
  the skip text names the real reason with the figures: "Sun protection skipped:
  sun at 8.4°, minimum 15°".
- With no installation position set, the sun condition blocks nothing: the
  moment is played anyway, just as with a silent sensor. The Health page counts
  those groups on a "Sun window" line.
- Existing groups are carried over on update: the facade is deduced from the
  settings in place, the "End of protection" moment and its commands are created,
  and one group failing does not deprive the others of their migration.
- The sun position is computed in pure PHP, with no dependency and no network
  call, atmospheric refraction corrected.

## 1.0

First release.

- Shutter groups: one group, three moments — morning, a midday sun protection,
  evening.
- Shutter selector: walks through the installation, groups by room, tells
  shutters from adjustable sun blinds and from devices matched by name, and
  raises, stops or lowers a shutter so you can identify it.
- An "inverted" checkbox per shutter, for the modules that publish 0 when they
  are open. Everywhere else the convention is 0% = closed, 100% = open.
- Trigger at a fixed time, or relative to sunrise or sunset with an offset in
  minutes.
- Weekdays, "not before" and "not after" time guards, random offset for presence
  simulation.
- A temperature condition per moment: do nothing below or above a threshold. A
  silent sensor blocks nothing, the moment is played anyway and the log says so.
- A default temperature sensor in the plugin configuration, which a group can
  override with its own.
- Preview of the next three occurrences of each moment, condition included.
- Commands: next change, open, close, stop, position, state, temperature used,
  last change, schedule active, pause, resume, next morning, next protection,
  next evening, sunrise and sunset.
- Automatic fallback when a shutter cannot do something: the slider stands in for
  up and down, up and down stand in for the slider.
- Pause a group without disabling it, drivable from a scenario.
- Catch-up for missed moments, adjustable.
- Health page: installation position, active groups, paused groups, scheduled
  shutters, and groups whose temperature condition has no readable sensor.
- No daemon, no dependency, no network call.
