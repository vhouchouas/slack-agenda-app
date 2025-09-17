This script will produce three graphics:
- calplot: a calendar heatmap, where the color is related to the duration sum of all events;
- occ_duration: a tag frequency histogram (among all events) on the right and cumulated event duration for each tag on the left;
- bene: histogram of (voluntary) work duration (cumulated among all events).

You will need to install the required python packages. Create a virtual environment

	python -m venv venv
	source venv/bin/activate
	
and install the packages

	pip install -r requirements.txt
	
Then export the agenda as a csv file:

	cd ..
	clitools csv-export
	
This will create a YYYY-MM-DD.csv file. Then

	mv YYYY-MM-DD.csv plot
	cd plot
	python plot.py --csv_file YYYY-MM-DD.csv --start 2022-02-01 --end 2023-01-31 --type pdf
	
to produce the graphics.
