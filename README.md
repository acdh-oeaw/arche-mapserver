# ARCHE maps dissemination service

Uses a [mapserver](https://mapserver.org/) to disseminate raster and vector ARCHE resources.

The mapserver is set up to:

* expose itself at `http://{container ip}/cgi-bin/mapserv`
* allow [map config files](https://mapserver.org/mapfile/index.html) only in the `/data` directory (subdirs can be used though)

The ARCHE dissemination service is set up to expose itself at `http://{container ip}/?id={arche resource URI or ID}&{optional mapserver request parameters}`.

The ARCHE dissemination service serves the request by:

* checking if the ARCHE resource is not cached locally and if not
  * download it into the `/data/{md5 sum of the ARCHE resource URI}`
  * creating a [map config file](https://mapserver.org/mapfile/index.html) for it in `/data/{md5 sum of the ARCHE resource URI}.map`
* redirecting to the mapserver

## Deployment

On each GitHub release a Docker image is rebuild and redeployed on the ACDH-CH cluster using [this GitHub Actions script](https://github.com/acdh-oeaw/arche-mapserver/blob/master/.github/workflows/deploy.yaml).

## Including static data

Static data can be added by extending [this file](https://github.com/acdh-oeaw/arche-mapserver/blob/master/build/arche/download.sh) and redeploying the service.
This script is run just after the container startup.
The data is downloaded after the container startup and not during the container build to avoid storing (potentially large amounts of) data inside the image.

Alternatively a share storing the static data can be mounted in the container under the `/data` directory or its subdirectory.

Please remember you have to provide the [map config files](https://mapserver.org/mapfile/index.html) for your data.
If you find the documentation linked above overwhelming, you can start with [minimalistic-yet-useful templates used by the ARCHE dissemination service](https://github.com/acdh-oeaw/arche-mapserver/tree/master/templates).
