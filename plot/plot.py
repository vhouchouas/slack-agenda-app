import locale
locale.setlocale(locale.LC_TIME, 'fr_FR.UTF-8')

import numpy as np
import matplotlib.pyplot as plt
import pandas as pd
import calplot
import sys
import os
import argparse
import datetime

def valid_date_type(arg_date_str):
    """custom argparse *date* type for user dates values given from the command line"""
    try:
        return datetime.datetime.strptime(arg_date_str, "%Y-%m-%d")
    except ValueError:
        msg = "Given Date ({0}) not valid! Expected format, YYYY-MM-DD!".format(arg_date_str)
        raise argparse.ArgumentTypeError(msg)

parser = argparse.ArgumentParser(prog = 'Agenda Plot')
parser.add_argument('-f', '--csv_file', dest='csv_file', type=argparse.FileType('r', encoding='utf-8'))
parser.add_argument('-t', '--types', dest='output_types', nargs='+', choices=['pdf', 'png'], required=True, help="type(s) of the exported files")
parser.add_argument('-s', '--start', type=valid_date_type, default=datetime.date(year=1970, day=1, month=1))
parser.add_argument('-e', '--end', type=valid_date_type, default=datetime.date.today())
args = parser.parse_args()

rescale = lambda y: (y - np.min(y)) / (np.max(y) - np.min(y))

cmap = plt.get_cmap("summer_r")

df = pd.read_csv(args.csv_file, sep=";")
df.columns =["start", "end", "attendees", "categories"]
df["duration"] = df.end-df.start
df.start = pd.to_datetime(df.start, unit='s')
df.end = pd.to_datetime(df.end, unit='s')

df = df[df['start'] >= args.start]
df = df[df['end'] <= args.end]

df['begin'] = df['start'].apply(lambda d: d.date())
df.begin = pd.to_datetime(df.begin)

cal = pd.concat([df.begin, df.duration], axis=1)
agg = cal.groupby('begin')['duration'].sum()/(60*60)

fig, ax = calplot.calplot(agg,
                          cmap='summer_r',#'YlGn',
                          suptitle="Distribution des événements sur l'année (couleurs = durée de l'événement en heures)",
                          suptitle_kws={'x': 0.35, 'y': 1.01})

for output in args.output_types:
    fig.savefig(f"calplot.{output}", bbox_inches='tight')

df["attendees"] = df["attendees"].apply(lambda x: [] if x !=x  else x.split(","))
df["categories"] = df["categories"].apply(lambda x: [] if x !=x  else [e.lower() for e in x.split(",")])

attendees = {}
categories_duration = {}
categories_occurrence = {}

for index, row in df.iterrows():
    
    for j in row["attendees"]:
        if j not in attendees:
            attendees[j] = row["duration"]/3600
        else:
            attendees[j] += row["duration"]/3600
    
    for j in row["categories"]:
        if j not in categories_duration:
            categories_duration[j] = row["duration"]/3600
            categories_occurrence[j] = 1
        else:
            categories_duration[j] += row["duration"]/3600
            categories_occurrence[j] += 1

categories_occurrence = {k: v for k, v in sorted(categories_occurrence.items(), key=lambda item: item[1], reverse=True)}
categories_duration = {k: v for k, v in sorted(categories_duration.items(), key=lambda item: item[1], reverse=True)}
            
A = np.array(list(attendees.values()))

values,bins = np.histogram(A, bins=int(np.ceil(np.max(A)/10)))
centers = 0.5*(bins[1:]+bins[:-1])

fig, ax = plt.subplots(ncols=1,nrows=1, figsize=(5,3))

rects1 = ax.bar(centers, values, width=centers[1]-centers[0], color=cmap(rescale(values)))
ax.set_xlabel("Nombre d'heures")
ax.set_title("Nombre bénévoles/salariées")
ax.set_xlim([0, np.max(centers)+1.5])
print(centers, values)
for output in args.output_types:
    fig.savefig(f"bene.{output}", bbox_inches='tight')

fig, (ax_occ, ax_duration) = plt.subplots(ncols=1,nrows=2, figsize=(8,6))
x = np.arange(len(categories_occurrence))

data = list(categories_occurrence.values())

ax_occ.bar(x, data, width=1, color=cmap(rescale(data)))
ax_occ.set_xlim([-0.5, len(data)-0.5])
data = list(categories_duration.values())
ax_duration.bar(x, data, width=1, color=cmap(rescale(data)))
ax_duration.set_xlim([-0.5, len(data)-0.5])

for ax in [ax_occ, ax_duration]:
    ax.set_xticks(x)
    ax.set_xticklabels(categories_occurrence, rotation=70, ha="right")

ax_occ.set_title("Nombre d'occurrences par tag")
ax_duration.set_ylabel("Nombre d'heures par tag")
fig.tight_layout()
for output in args.output_types:
    fig.savefig(f"occ_duration.{output}", bbox_inches='tight')
