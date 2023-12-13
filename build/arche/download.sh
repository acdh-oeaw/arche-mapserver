#!/bin/bash
curl https://shared.acdh.oeaw.ac.at/histogis/czoernig.map > /data/czoernig.map
curl https://shared.acdh.oeaw.ac.at/histogis/czoernig.tif > /data/czoernig.tif
curl https://shared.acdh.oeaw.ac.at/histogis/tirol.map > /data/tirol.map
curl https://shared.acdh.oeaw.ac.at/histogis/tirol.tif > /data/tirol.tif

chown www-data:www-data /data/*
