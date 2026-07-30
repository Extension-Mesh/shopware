import './service/extension-mesh-api.service';
import './module/extension-mesh';
import './extension/sw-extension-my-extensions-index';
import './extension/sw-extension-my-extensions-listing';
import './extension/sw-self-maintained-extension-card';
import './extension/sw-extension-card-base';
import './extension/sw-product-detail';
import './extension/sw-product-download-form';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);
