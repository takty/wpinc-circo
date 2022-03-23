/**
 * Gulp file
 *
 * @author Takuto Yanagida
 * @version 2022-03-23
 */

/* eslint-disable no-undef */
'use strict';

const SRC_PHP = ['src/**/*.php'];
const DEST    = './dist';

const gulp = require('gulp');

const { makeCopyTask } = require('./task-copy');


// -----------------------------------------------------------------------------


const php = makeCopyTask(SRC_PHP, DEST);

const watch = done => {
	gulp.watch(SRC_PHP, gulp.series(php));
	done();
};

exports.build   = gulp.parallel(php);
exports.default = gulp.series(exports.build , watch);
