# Dreame Robot for IP-Symcon

Experimental IP-Symcon 9.0 module for Dreame robot vacuums connected to the
Dreamehome app. Version 0.4 targets the **Dreame X60 Pro Ultra Complete** and
adds parallel multi-robot cleaning plans and an experimental live-map view.

## Current feature set

- Dreamehome login through the European endpoint (`de`) used for Germany and
  Switzerland
- Vacuum discovery and automatic X60 selection
- State, battery, charging state, error, cleaning time and cleaned area
- Start/resume, pause, return to dock, stop and locate
- Cleaning modes: vacuum only, mop only, vacuum and mop
- Any number of separately configured robot instances
- Dreamehome shortcut discovery and execution
- Parallel jobs on different robots and different logical areas
- Dependencies that create a vacuum-to-mop pipeline across smaller areas
- Area locks, one active job per robot and a central completion ledger
- One dashboard containing the live maps of multiple robots
- Robot and charging-station markers on every map
- Encrypted X60 (`dreame.vacuum.r6001a`) map-file decoding
- Refresh-token reuse and increasing retry delay after communication failures

Rooms and zones are selected through shortcuts created in the Dreamehome app.
Dreame does not make the robots communicate directly; IP-Symcon is the central
coordinator and keeps the shared locks and completion record.

## Installation and first test

1. Add this repository to **Module Control** in IP-Symcon, or copy the complete
   `Dreame-Symcon` directory to a repository used by Module Control.
2. Create a **Dreame Saugwischroboter** instance.
3. Select the Dreamehome region in which the robot is registered.
4. Enter the same email address or phone number and password used by the
   Dreamehome app.
5. Leave the device ID empty for the first test.
6. Click **Anmeldung testen und Geräte suchen**.
7. If more than one robot is listed, copy the desired device ID into the field.
8. Enable **Aktiv** and apply the changes.
9. Click **Live-Karte testen**. A successful first test reports the dimensions,
   robot position and whether the data came directly from the robot or from a
   cloud file.

## Configure multiple robots

1. Create one **Dreame Saugwischroboter** instance for each physical robot.
2. Use the same Dreamehome account in every instance, but enter the matching
   device ID shown by the connection test.
3. Test every robot individually before creating a joint plan.
4. In Dreamehome, create small room or zone shortcuts for every robot. The
   shortcut itself contains the rooms/zones and whether it vacuums, mops or
   does both.
5. In each IP-Symcon robot instance click **Dreame-Kurzbefehle anzeigen** and
   note the displayed shortcut IDs.
6. Create one **Dreame Mehrroboter-Koordinator** instance.
7. Add at least two enabled jobs. Each job needs a unique job ID, a robot, a
   logical area name, the shortcut ID, the expected result, optional
   prerequisite job IDs and optional shared lock areas.
8. Enable the coordinator, apply the changes and click
   **Reinigungsfolge starten**.
9. Create one **Dreame Mehrroboter-Karte** instance, add both robot instances,
   choose different marker colours, enable it and apply the changes.

Jobs on separate robots and with different area names start simultaneously.
The coordinator never starts two jobs on the same robot or logical area. A job
with prerequisites starts only after every named prerequisite has completed.
It also waits until IP-Symcon has observed a robot actively cleaning before a
later idle state counts as successful completion.

### Example: dental practice pipeline

Create smaller patient areas instead of one very large shortcut, for example
`Waiting`, `Reception` and `PatientHall`. A possible plan is:

| Job ID | Robot | Logical area | Result | Wait for | Additional locks |
| --- | --- | --- | --- | --- | --- |
| `patient_wait_vac` | 1 | Waiting | Vacuumed | - | - |
| `staff_clean` | 2 | StaffArea | Vacuumed and mopped | - | `StaffHall` |
| `patient_reception_vac` | 1 | Reception | Vacuumed | `patient_wait_vac` | - |
| `patient_wait_mop` | 2 | Waiting | Mopped | `staff_clean,patient_wait_vac` | - |
| `patient_reception_mop` | 2 | Reception | Mopped | `patient_wait_mop,patient_reception_vac` | - |

The first two jobs start together. Robot 2 later mops `Waiting` as soon as its
staff job and robot 1's waiting-room vacuum job are both complete. At that time
robot 1 can already be vacuuming `Reception`. Use exactly the same logical area
name for the vacuum and mop jobs so the coordinator's area lock applies.
Additional locks can represent narrow shared corridors, doors or station
access. Two jobs containing the same additional lock are not started at the
same time. Leave this field empty where parallel operation is physically safe;
a broad lock such as `PatientArea` would intentionally serialize all patient
jobs and prevent the pipeline shown above.

The **Flächenprotokoll** variable records which robot completed vacuuming or
mopping for every logical area.

### Multi-robot map

Version 0.4 deliberately displays the robots' live maps side by side in one
responsive dashboard. Both current robot positions and both charging stations
are visible. This is the reliable first step because two robots can store the
same floor with different origins, rotations and room identifiers.

An exact overlay of both robots on one floor plan needs calibration values for
translation and rotation. Those values should only be added after maps from the
two real X60 devices have been retrieved successfully. The map is requested
through the undocumented Dreamehome cloud interface and is therefore not a
hard real-time position feed.

### Collision limitation

Area locks prevent the coordinator from deliberately assigning both robots to
the same logical area. The robots may still choose crossing travel paths
between stations and rooms, and map/cloud status is not real-time. Therefore
the module cannot guarantee physical collision avoidance based on coordinates.
Keep simultaneous jobs in clearly separated room groups; the robots' own
obstacle avoidance remains the final safety layer.

Do not post screenshots or debug logs without removing account names, device
IDs, MAC addresses and tokens.

## Reliability notes

Each robot polls once per minute by default. While a joint sequence is active,
the coordinator checks the current robot every 30 seconds by default. After
ordinary robot-instance errors, the wait time increases up to 15 minutes
instead of continuously retrying. A successful request restores the configured
interval.

The access and refresh tokens are kept as non-visible IP-Symcon instance
attributes. The configured password remains an IP-Symcon property so that the
module can recover if Dreame invalidates the refresh token.

## Disclaimer

The Dreamehome interface is undocumented and can change without notice. This
project is not affiliated with or endorsed by Dreame Technology. See
[`NOTICE.md`](NOTICE.md) for the protocol reference attribution.
