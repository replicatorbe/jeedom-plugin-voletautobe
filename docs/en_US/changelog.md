# Changelog

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
