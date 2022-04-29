/******/ (() => { // webpackBootstrap
/******/ 	// runtime can't be in strict mode because a global variable is assign and maybe created.
/******/ 	var __webpack_modules__ = ({

/***/ "./src/forum/index.js":
/*!****************************!*\
  !*** ./src/forum/index.js ***!
  \****************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

"use strict";
__webpack_require__.r(__webpack_exports__);
/* harmony import */ var flarum_forum_app__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! flarum/forum/app */ "flarum/forum/app");
/* harmony import */ var flarum_forum_app__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(flarum_forum_app__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var flarum_common_extend__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! flarum/common/extend */ "flarum/common/extend");
/* harmony import */ var flarum_common_extend__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(flarum_common_extend__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var flarum_forum_components_CommentPost__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! flarum/forum/components/CommentPost */ "flarum/forum/components/CommentPost");
/* harmony import */ var flarum_forum_components_CommentPost__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(flarum_forum_components_CommentPost__WEBPACK_IMPORTED_MODULE_2__);



flarum_forum_app__WEBPACK_IMPORTED_MODULE_0___default().initializers.add('datlechin/flarum-link-preview', function () {
  (0,flarum_common_extend__WEBPACK_IMPORTED_MODULE_1__.extend)((flarum_forum_components_CommentPost__WEBPACK_IMPORTED_MODULE_2___default().prototype), 'oncreate', function () {
    var blacklist = flarum_forum_app__WEBPACK_IMPORTED_MODULE_0___default().forum.attribute('datlechin-link-preview.blacklist');
    var blacklistArray = blacklist ? blacklist.split(',').map(function (item) {
      return item.trim();
    }) : [];
    var links = this.element.querySelectorAll('.Post-body a[rel]');
    links.forEach(function (link) {
      var href = link.getAttribute('href');
      var domain = href.split('/')[2].replace('www.', '');

      if (!link.classList.contains('PostMention') || !link.classList.contains('UserMention')) {
        if (href === link.textContent && !blacklistArray.includes(domain) && !blacklistArray.includes(href)) {
          if (!flarum_forum_app__WEBPACK_IMPORTED_MODULE_0___default().forum.attribute('datlechin-link-preview.convertMediaURLs')) {
            if (href.match(/\.(jpe?g|png|gif|svg|webp|mp3|mp4|m4a|wav)$/)) return;
          }

          var siteUrl = href.split('/')[0] + '//' + domain;
          var linkPreviewWrapper = document.createElement('div');
          var linkPreviewImage = document.createElement('div');
          var linkPreviewImg = document.createElement('img');
          var linkPreviewMain = document.createElement('div');
          var linkPreviewTitle = document.createElement('div');
          var linkPreviewTitleURL = document.createElement('a');
          var linkPreviewDescription = document.createElement('div');
          var linkPreviewDomain = document.createElement('div');
          var linkPreviewDomainURL = document.createElement('a');
          var linkPreviewDomainFavicon = document.createElement('img');
          var linkPreviewLoadingIcon = document.createElement('i');
          linkPreviewWrapper.classList.add('LinkPreview');
          linkPreviewImage.classList.add('LinkPreview-image');
          linkPreviewMain.classList.add('LinkPreview-main');
          linkPreviewTitle.classList.add('LinkPreview-title');
          linkPreviewTitleURL.target = '_blank';
          linkPreviewDescription.classList.add('LinkPreview-description');
          linkPreviewDomain.classList.add('LinkPreview-domain');
          linkPreviewDomainURL.href = href;
          linkPreviewDomainURL.target = '_blank';
          linkPreviewDomainURL.textContent = domain;
          linkPreviewDomainURL.href = siteUrl;
          linkPreviewDomainFavicon.setAttribute('src', 'https://www.google.com/s2/favicons?sz=64&domain_url=' + siteUrl);
          linkPreviewLoadingIcon.classList.add('fa', 'fa-spinner', 'fa-spin');
          link.parentNode.insertBefore(linkPreviewWrapper, link);
          linkPreviewWrapper.appendChild(link);
          linkPreviewWrapper.appendChild(linkPreviewImage);
          linkPreviewImage.appendChild(linkPreviewImg);
          linkPreviewWrapper.appendChild(linkPreviewMain);
          linkPreviewMain.appendChild(linkPreviewTitle);
          linkPreviewTitle.appendChild(linkPreviewTitleURL);
          linkPreviewMain.appendChild(linkPreviewDescription);
          linkPreviewMain.appendChild(linkPreviewDomain);
          linkPreviewDomain.appendChild(linkPreviewDomainFavicon);
          linkPreviewDomain.appendChild(linkPreviewDomainURL);
          linkPreviewImage.appendChild(linkPreviewLoadingIcon);
          link.remove();
          flarum_forum_app__WEBPACK_IMPORTED_MODULE_0___default().request({
            url: flarum_forum_app__WEBPACK_IMPORTED_MODULE_0___default().forum.attribute('apiUrl') + '/datlechin-link-preview?url=' + encodeURIComponent(href),
            method: 'GET'
          }).then(function (data) {
            var _data$image, _data$url, _data$title, _data$description, _data$site_name;

            linkPreviewLoadingIcon.remove();
            linkPreviewImg.setAttribute('src', (_data$image = data.image) != null ? _data$image : 'https://www.google.com/s2/favicons?sz=64&domain_url=' + siteUrl);
            linkPreviewTitleURL.href = (_data$url = data.url) != null ? _data$url : href;
            linkPreviewTitleURL.textContent = (_data$title = data.title) != null ? _data$title : domain;
            linkPreviewDescription.textContent = (_data$description = data.description) != null ? _data$description : '';
            linkPreviewDomainURL.textContent = (_data$site_name = data.site_name) != null ? _data$site_name : domain;
            if (data.error) linkPreviewTitleURL.textContent = flarum_forum_app__WEBPACK_IMPORTED_MODULE_0___default().translator.trans('datlechin-link-preview.forum.site_cannot_be_reached');
          });
        }
      }
    });
  });
});

/***/ }),

/***/ "flarum/common/extend":
/*!******************************************************!*\
  !*** external "flarum.core.compat['common/extend']" ***!
  \******************************************************/
/***/ ((module) => {

"use strict";
module.exports = flarum.core.compat['common/extend'];

/***/ }),

/***/ "flarum/forum/app":
/*!**************************************************!*\
  !*** external "flarum.core.compat['forum/app']" ***!
  \**************************************************/
/***/ ((module) => {

"use strict";
module.exports = flarum.core.compat['forum/app'];

/***/ }),

/***/ "flarum/forum/components/CommentPost":
/*!*********************************************************************!*\
  !*** external "flarum.core.compat['forum/components/CommentPost']" ***!
  \*********************************************************************/
/***/ ((module) => {

"use strict";
module.exports = flarum.core.compat['forum/components/CommentPost'];

/***/ })

/******/ 	});
/************************************************************************/
/******/ 	// The module cache
/******/ 	var __webpack_module_cache__ = {};
/******/ 	
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/ 		// Check if module is in cache
/******/ 		var cachedModule = __webpack_module_cache__[moduleId];
/******/ 		if (cachedModule !== undefined) {
/******/ 			return cachedModule.exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = __webpack_module_cache__[moduleId] = {
/******/ 			// no module.id needed
/******/ 			// no module.loaded needed
/******/ 			exports: {}
/******/ 		};
/******/ 	
/******/ 		// Execute the module function
/******/ 		__webpack_modules__[moduleId](module, module.exports, __webpack_require__);
/******/ 	
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/ 	
/************************************************************************/
/******/ 	/* webpack/runtime/compat get default export */
/******/ 	(() => {
/******/ 		// getDefaultExport function for compatibility with non-harmony modules
/******/ 		__webpack_require__.n = (module) => {
/******/ 			var getter = module && module.__esModule ?
/******/ 				() => (module['default']) :
/******/ 				() => (module);
/******/ 			__webpack_require__.d(getter, { a: getter });
/******/ 			return getter;
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/define property getters */
/******/ 	(() => {
/******/ 		// define getter functions for harmony exports
/******/ 		__webpack_require__.d = (exports, definition) => {
/******/ 			for(var key in definition) {
/******/ 				if(__webpack_require__.o(definition, key) && !__webpack_require__.o(exports, key)) {
/******/ 					Object.defineProperty(exports, key, { enumerable: true, get: definition[key] });
/******/ 				}
/******/ 			}
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/hasOwnProperty shorthand */
/******/ 	(() => {
/******/ 		__webpack_require__.o = (obj, prop) => (Object.prototype.hasOwnProperty.call(obj, prop))
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/make namespace object */
/******/ 	(() => {
/******/ 		// define __esModule on exports
/******/ 		__webpack_require__.r = (exports) => {
/******/ 			if(typeof Symbol !== 'undefined' && Symbol.toStringTag) {
/******/ 				Object.defineProperty(exports, Symbol.toStringTag, { value: 'Module' });
/******/ 			}
/******/ 			Object.defineProperty(exports, '__esModule', { value: true });
/******/ 		};
/******/ 	})();
/******/ 	
/************************************************************************/
var __webpack_exports__ = {};
// This entry need to be wrapped in an IIFE because it need to be in strict mode.
(() => {
"use strict";
/*!******************!*\
  !*** ./forum.js ***!
  \******************/
__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _src_forum__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./src/forum */ "./src/forum/index.js");

})();

module.exports = __webpack_exports__;
/******/ })()
;
//# sourceMappingURL=forum.js.map