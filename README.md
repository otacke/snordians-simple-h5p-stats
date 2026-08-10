# SNORDIAN's Simple H5P Stats

## Installation
Install Simple H5P Stats on your Wordpress instance by uploading the zip file and activate it. Done.

## Setup
Once activated, the plugin will log hits to all H5P content types that you provide access to on your WordPress blog. However, it will not count calls from admins or from the content creators themselves if logged it in order to not mess with the statistics.

In order to tweak your setup, you can change these settings vie the "Simple H5P Stats" settings menu item:

1) __Count hits to your content that is embedded on other websites__
This settings is not enabled by default. So without changes, hits to H5P content that was embedded to a different website will not be tracked. If that is what you want, just check the checkbox.

2) __Count only unique visitors per day__
This settings is enabled by default. This means that the plugin will try to detect returning visitors and only count one hit per day/per H5P content. This is a common measure to avoid counting hits causes by page reloads, etc. Uncheck if you really want to count each and every call to the H5P content.

## License
Simple H5P Stats is is licensed under the [MIT License](https://github.com/otacke/snordians-simple-h5p-stats/blob/master/LICENSE).
