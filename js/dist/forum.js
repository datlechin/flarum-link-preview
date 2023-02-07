/******/ (() => { // webpackBootstrap
/******/ 	// runtime can't be in strict mode because a global variable is assign and maybe created.
/******/ 	var __webpack_modules__ = ({

/***/ "./src/forum/components/LinkPreview.js":
/*!*********************************************!*\
  !*** ./src/forum/components/LinkPreview.js ***!
  \*********************************************/
/***/ ((__unused_webpack_module, __webpack_exports__, __webpack_require__) => {

"use strict";
__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ LinkPreview)
/* harmony export */ });
/* harmony import */ var _babel_runtime_helpers_esm_inheritsLoose__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @babel/runtime/helpers/esm/inheritsLoose */ "./node_modules/@babel/runtime/helpers/esm/inheritsLoose.js");
/* harmony import */ var flarum_common_Component__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! flarum/common/Component */ "flarum/common/Component");
/* harmony import */ var flarum_common_Component__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(flarum_common_Component__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var flarum_common_helpers_icon__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! flarum/common/helpers/icon */ "flarum/common/helpers/icon");
/* harmony import */ var flarum_common_helpers_icon__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(flarum_common_helpers_icon__WEBPACK_IMPORTED_MODULE_2__);
/* harmony import */ var flarum_common_components_Link__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! flarum/common/components/Link */ "flarum/common/components/Link");
/* harmony import */ var flarum_common_components_Link__WEBPACK_IMPORTED_MODULE_3___default = /*#__PURE__*/__webpack_require__.n(flarum_common_components_Link__WEBPACK_IMPORTED_MODULE_3__);
/* harmony import */ var flarum_common_utils_classList__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! flarum/common/utils/classList */ "flarum/common/utils/classList");
/* harmony import */ var flarum_common_utils_classList__WEBPACK_IMPORTED_MODULE_4___default = /*#__PURE__*/__webpack_require__.n(flarum_common_utils_classList__WEBPACK_IMPORTED_MODULE_4__);
/* harmony import */ var flarum_common_components_LoadingIndicator__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! flarum/common/components/LoadingIndicator */ "flarum/common/components/LoadingIndicator");
/* harmony import */ var flarum_common_components_LoadingIndicator__WEBPACK_IMPORTED_MODULE_5___default = /*#__PURE__*/__webpack_require__.n(flarum_common_components_LoadingIndicator__WEBPACK_IMPORTED_MODULE_5__);







var LinkPreview = /*#__PURE__*/function (_Component) {
  (0,_babel_runtime_helpers_esm_inheritsLoose__WEBPACK_IMPORTED_MODULE_0__["default"])(LinkPreview, _Component);

  function LinkPreview() {
    return _Component.apply(this, arguments) || this;
  }

  var _proto = LinkPreview.prototype;

  _proto.oninit = function oninit(vnode) {
    _Component.prototype.oninit.call(this, vnode);

    this.loading = true;
    this.link = vnode.attrs.link;
    this.linkAttributes = Object.assign.apply(Object, [{}].concat(Array.from(this.link.attributes, function (_ref) {
      var _ref2;

      var name = _ref.name,
          value = _ref.value;
      return _ref2 = {}, _ref2[name] = value, _ref2;
    })));
    this.data = null;
    this.useGoogleFavicons = vnode.attrs.useGoogleFavicons;
    this.fetchData();
  };

  _proto.view = function view() {
    var _this$data$title, _this$data, _this$data2, _this$data$descriptio, _this$data3, _this$data4;

    var classes = {
      loading: this.loading
    };
    return m("div", {
      className: 'LinkPreview ' + flarum_common_utils_classList__WEBPACK_IMPORTED_MODULE_4___default()(classes)
    }, this.loading || this.getImage() ? m("div", {
      className: "LinkPreview-image"
    }, this.loading ? m((flarum_common_components_LoadingIndicator__WEBPACK_IMPORTED_MODULE_5___default()), {
      display: "unset",
      containerClassName: flarum_common_utils_classList__WEBPACK_IMPORTED_MODULE_4___default()('LinkPreview-loading', this.loading && 'active'),
      size: "small"
    }) : m("img", {
      src: this.getImage(),
      "data-link-preview": true
    })) : null, m("div", {
      className: "LinkPreview-main"
    }, m("div", {
      className: "LinkPreview-title"
    }, this.getLink((_this$data$title = (_this$data = this.data) == null ? void 0 : _this$data.title) != null ? _this$data$title : (_this$data2 = this.data) == null ? void 0 : _this$data2.error)), m("div", {
      className: "LinkPreview-description"
    }, this.loading ? '' : (_this$data$descriptio = (_this$data3 = this.data) == null ? void 0 : _this$data3.description) != null ? _this$data$descriptio : ''), m("div", {
      className: "LinkPreview-domain"
    }, this.useGoogleFavicons ? m("img", {
      src: this.getFavicon(),
      "data-link-preview": true
    }) : flarum_common_helpers_icon__WEBPACK_IMPORTED_MODULE_2___default()('fas fa-external-link-alt'), this.getLink((_this$data4 = this.data) == null ? void 0 : _this$data4.site_name))));
  };

  _proto.oncreate = function oncreate(vnode) {
    this.link.parentNode.insertBefore(vnode.dom, this.link);
  };

  _proto.getLink = function getLink(text) {
    return m((flarum_common_components_Link__WEBPACK_IMPORTED_MODULE_3___default()), this.linkAttributes, this.loading ? this.getDomain() : text != null ? text : this.getDomain());
  };

  _proto.getHref = function getHref() {
    return this.link.href;
  };

  _proto.getDomain = function getDomain() {
    return this.getHref().split('/')[2];
  };

  _proto.getImage = function getImage() {
    var _this$data$image, _this$data5;

    return (_this$data$image = (_this$data5 = this.data) == null ? void 0 : _this$data5.image) != null ? _this$data$image : this.getFavicon();
  };

  _proto.getFavicon = function getFavicon() {
    return this.useGoogleFavicons ? 'https://www.google.com/s2/favicons?sz=64&domain_url=' + this.getDomain() : null;
  };

  _proto.fetchData = function fetchData() {
    var _this = this;

    app.request({
      url: app.forum.attribute('apiUrl') + '/datlechin-link-preview?url=' + encodeURIComponent(this.getHref()),
      method: 'GET'
    }).then(function (data) {
      _this.setData(data);

      _this.loading = false;
    });
  };

  _proto.setData = function setData(data) {
    this.data = data;
    m.redraw();
  };

  return LinkPreview;
}((flarum_common_Component__WEBPACK_IMPORTED_MODULE_1___default()));



/***/ }),

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
/* harmony import */ var _components_LinkPreview__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./components/LinkPreview */ "./src/forum/components/LinkPreview.js");
function _createForOfIteratorHelperLoose(o, allowArrayLike) { var it = typeof Symbol !== "undefined" && o[Symbol.iterator] || o["@@iterator"]; if (it) return (it = it.call(o)).next.bind(it); if (Array.isArray(o) || (it = _unsupportedIterableToArray(o)) || allowArrayLike && o && typeof o.length === "number") { if (it) o = it; var i = 0; return function () { if (i >= o.length) return { done: true }; return { done: false, value: o[i++] }; }; } throw new TypeError("Invalid attempt to iterate non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); }

function _unsupportedIterableToArray(o, minLen) { if (!o) return; if (typeof o === "string") return _arrayLikeToArray(o, minLen); var n = Object.prototype.toString.call(o).slice(8, -1); if (n === "Object" && o.constructor) n = o.constructor.name; if (n === "Map" || n === "Set") return Array.from(o); if (n === "Arguments" || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(n)) return _arrayLikeToArray(o, minLen); }

function _arrayLikeToArray(arr, len) { if (len == null || len > arr.length) len = arr.length; for (var i = 0, arr2 = new Array(len); i < len; i++) { arr2[i] = arr[i]; } return arr2; }





flarum_forum_app__WEBPACK_IMPORTED_MODULE_0___default().initializers.add('datlechin/flarum-link-preview', function () {
  (0,flarum_common_extend__WEBPACK_IMPORTED_MODULE_1__.extend)((flarum_forum_components_CommentPost__WEBPACK_IMPORTED_MODULE_2___default().prototype), 'refreshContent', function () {
    var _app$forum$attribute;

    var getMultiDimensionalSetting = function getMultiDimensionalSetting(key) {
      var setting = flarum_forum_app__WEBPACK_IMPORTED_MODULE_0___default().forum.attribute(key);
      return setting ? setting.split(/[,\n]/).map(function (item) {
        return item.trim();
      }) : [];
    };

    var inList = function inList(needle, haystack) {
      if (0 === haystack.length) {
        return false;
      }

      if (haystack.includes(needle)) {
        return true;
      }

      for (var _iterator = _createForOfIteratorHelperLoose(haystack), _step; !(_step = _iterator()).done;) {
        var item = _step.value;
        var quoted = item.replace(/[-\[\]\/\{\}\(\)\*\+\?\.\\\^\$\|]/g, '\\$&').replace('\\*', '.*').replace('\\?', '.');

        if (needle.match(new RegExp(quoted, 'i'))) {
          return true;
        }
      }

      return false;
    };

    var blacklistArray = getMultiDimensionalSetting('datlechin-link-preview.blacklist');
    var whitelistArray = getMultiDimensionalSetting('datlechin-link-preview.whitelist');
    var useGoogleFavicons = (_app$forum$attribute = flarum_forum_app__WEBPACK_IMPORTED_MODULE_0___default().forum.attribute('datlechin-link-preview.useGoogleFavicons')) != null ? _app$forum$attribute : false;
    this.element.querySelectorAll('.Post-body a[rel]').forEach(function (link) {
      if (link.classList.contains('PostMention') || link.classList.contains('UserMention')) {
        return;
      }

      var normalizedUrl = link.href.replace(/^https?:\/\/(.+?)\/?$/i, '$1');

      if (whitelistArray.length && !inList(normalizedUrl, whitelistArray) || blacklistArray.length && inList(normalizedUrl, blacklistArray) || link.href.replace(/\/$/, '') !== link.textContent.replace(/\/$/, '')) {
        return;
      }

      if (flarum_forum_app__WEBPACK_IMPORTED_MODULE_0___default().forum.attribute('datlechin-link-preview.convertMediaURLs') && normalizedUrl.match(/\.(jpe?g|png|gif|svg|webp|mp3|mp4|m4a|wav)$/)) {
        return;
      }

      m.mount(link, {
        view: function view() {
          return m(_components_LinkPreview__WEBPACK_IMPORTED_MODULE_3__["default"], {
            link: link,
            useGoogleFavicons: useGoogleFavicons
          });
        }
      });
    });
  });
});

/***/ }),

/***/ "flarum/common/Component":
/*!*********************************************************!*\
  !*** external "flarum.core.compat['common/Component']" ***!
  \*********************************************************/
/***/ ((module) => {

"use strict";
module.exports = flarum.core.compat['common/Component'];

/***/ }),

/***/ "flarum/common/components/Link":
/*!***************************************************************!*\
  !*** external "flarum.core.compat['common/components/Link']" ***!
  \***************************************************************/
/***/ ((module) => {

"use strict";
module.exports = flarum.core.compat['common/components/Link'];

/***/ }),

/***/ "flarum/common/components/LoadingIndicator":
/*!***************************************************************************!*\
  !*** external "flarum.core.compat['common/components/LoadingIndicator']" ***!
  \***************************************************************************/
/***/ ((module) => {

"use strict";
module.exports = flarum.core.compat['common/components/LoadingIndicator'];

/***/ }),

/***/ "flarum/common/extend":
/*!******************************************************!*\
  !*** external "flarum.core.compat['common/extend']" ***!
  \******************************************************/
/***/ ((module) => {

"use strict";
module.exports = flarum.core.compat['common/extend'];

/***/ }),

/***/ "flarum/common/helpers/icon":
/*!************************************************************!*\
  !*** external "flarum.core.compat['common/helpers/icon']" ***!
  \************************************************************/
/***/ ((module) => {

"use strict";
module.exports = flarum.core.compat['common/helpers/icon'];

/***/ }),

/***/ "flarum/common/utils/classList":
/*!***************************************************************!*\
  !*** external "flarum.core.compat['common/utils/classList']" ***!
  \***************************************************************/
/***/ ((module) => {

"use strict";
module.exports = flarum.core.compat['common/utils/classList'];

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

/***/ }),

/***/ "./node_modules/@babel/runtime/helpers/esm/inheritsLoose.js":
/*!******************************************************************!*\
  !*** ./node_modules/@babel/runtime/helpers/esm/inheritsLoose.js ***!
  \******************************************************************/
/***/ ((__unused_webpack___webpack_module__, __webpack_exports__, __webpack_require__) => {

"use strict";
__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ _inheritsLoose)
/* harmony export */ });
/* harmony import */ var _setPrototypeOf_js__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./setPrototypeOf.js */ "./node_modules/@babel/runtime/helpers/esm/setPrototypeOf.js");

function _inheritsLoose(subClass, superClass) {
  subClass.prototype = Object.create(superClass.prototype);
  subClass.prototype.constructor = subClass;
  (0,_setPrototypeOf_js__WEBPACK_IMPORTED_MODULE_0__["default"])(subClass, superClass);
}

/***/ }),

/***/ "./node_modules/@babel/runtime/helpers/esm/setPrototypeOf.js":
/*!*******************************************************************!*\
  !*** ./node_modules/@babel/runtime/helpers/esm/setPrototypeOf.js ***!
  \*******************************************************************/
/***/ ((__unused_webpack___webpack_module__, __webpack_exports__, __webpack_require__) => {

"use strict";
__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ _setPrototypeOf)
/* harmony export */ });
function _setPrototypeOf(o, p) {
  _setPrototypeOf = Object.setPrototypeOf ? Object.setPrototypeOf.bind() : function _setPrototypeOf(o, p) {
    o.__proto__ = p;
    return o;
  };
  return _setPrototypeOf(o, p);
}

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