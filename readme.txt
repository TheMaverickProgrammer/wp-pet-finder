== DESCRIPTION ==
Petfinder Local Database Plugin for Wordpress creates a local
copy of Petfinder pets queried for faster webpage loading.

== WORD PRESS SHORT CODES ==
[petfinder-update-pets] 
	Updates the database from a shelter. Drops any adopted pets.
[petfinder-display-pets]
	Displays all the pets in the database in a specified format. 
	Default format if not provided.
	
	Optional arguments:
	animal={CAT or DOG}
	count={how many pets to display}

example: [petfinder-dipsplay-pets anima=DOG count=400]

== SETTINGS ==
Plugin settings are found under the “Settings >> Pet Finder WP” page 
in the Administrator view.

An API KEY, API SECRET, and SHELTER ID are REQUIRED for the plugin to work.
To get these, you must register on the per finder’s developers portal:
https://www.petfinder.com/developers/api-docs