/**
 * Gulp file
 *
 * @author Takuto Yanagida
 * @version 2022-12-08
 */

const SRC_PHP = ['src/**/*.php'];
const DEST    = './dist';

import gulp from 'gulp';

import { makeCopyTask } from './gulp/task-copy.mjs';

const php = makeCopyTask(SRC_PHP, DEST);

const watch = done => {
	gulp.watch(SRC_PHP, gulp.series(php));
	done();
};

export const build = gulp.parallel(php);
export default gulp.series(build , watch);
